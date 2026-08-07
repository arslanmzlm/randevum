<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser regression — calendar week/day are drawn on the ACTIVE clinic's time axis (working
 * hours, closed bands, doctor columns). That axis is meaningless for any other branch, so month
 * is the only valid view once more than one branch is selected (crossBranch). Two entry points
 * used to bypass that guard by writing `activeView.value = 'day'` unconditionally after narrowing
 * the branch/doctor filter from a month-view chip click, instead of re-checking crossBranch against
 * the NEW filter state: clicking a non-active branch's day-summary chip, and clicking a doctor
 * chip while a single non-active branch was still selected. Both are exercised here.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ccaRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('keeps month view (never opens day/week) when a month-summary chip is clicked for a non-active branch', function (): void {
    // Lower clinic id is the tenant's default active clinic (ClinicMembershipService orders by
    // clinic_id), so clinicA is created first and is the one NOT clicked.
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'name' => 'Merkez Klinik']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'timezone' => 'Europe/Istanbul', 'name' => 'Filyos Şubesi']);

    $owner = User::factory()->create();
    ccaRole($owner, 'owner', $clinicA->id);
    ccaRole($owner, 'owner', $clinicB->id);

    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $date = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    Appointment::factory()->create([
        'clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id,
        'starts_at' => Carbon::parse("{$date} 10:00:00", 'Europe/Istanbul')->utc(),
        'ends_at' => Carbon::parse("{$date} 11:00:00", 'Europe/Istanbul')->utc(),
        'status' => AppointmentStatus::Confirmed, 'is_walk_in' => false,
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'starts_at' => Carbon::parse("{$date} 12:00:00", 'Europe/Istanbul')->utc(),
        'ends_at' => Carbon::parse("{$date} 13:00:00", 'Europe/Istanbul')->utc(),
        'status' => AppointmentStatus::Confirmed, 'is_walk_in' => false,
    ]);

    $this->actingAs($owner);

    $page = visit('/calendar')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Open the branch filter and select both branches → crossBranch, forced to month.
        ->click('.p-multiselect-label:text-is("Tüm şubeler")')
        ->click('span[data-pc-section="optionlabel"]:text-is("Merkez Klinik")')
        ->click('span[data-pc-section="optionlabel"]:text-is("Filyos Şubesi")')
        ->click('h1:text-is("Takvim")')
        ->assertSee('Filyos Şubesi')
        ->assertPresent('button[aria-pressed="true"]:has-text("Ay")')
        ->assertPresent('button[disabled]:has-text("Gün")');

    // Click the OTHER branch's (clinicB, not the active clinic) month-summary chip for that day.
    // Before the fix this dropped straight into day view on the active clinic's time axis.
    $page->click('button:has(span:text-is("Filyos Şubesi"))')
        ->assertNoJavascriptErrors()
        ->assertPresent('button[aria-pressed="true"]:has-text("Ay")')
        ->assertPresent('button[disabled]:has-text("Hafta")')
        ->assertPresent('button[disabled]:has-text("Gün")')
        ->assertMissing('.p-selectbutton button[aria-pressed="true"]:has-text("Gün")')
        ->screenshot();
});

it('keeps month view when a doctor chip is clicked while a single non-active branch is still selected', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'name' => 'Merkez Klinik']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'timezone' => 'Europe/Istanbul', 'name' => 'Filyos Şubesi']);

    $owner = User::factory()->create();
    ccaRole($owner, 'owner', $clinicA->id);
    ccaRole($owner, 'owner', $clinicB->id);

    // display_name is a computed accessor ("{$title} {$user->name}"), not a column — pin both
    // parts so the chip's rendered text is deterministic.
    $doctorUserB = User::factory()->create(['first_name' => 'Bade', 'last_name' => 'Yılmaz']);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id, 'title' => 'Dr.']);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $date = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'starts_at' => Carbon::parse("{$date} 12:00:00", 'Europe/Istanbul')->utc(),
        'ends_at' => Carbon::parse("{$date} 13:00:00", 'Europe/Istanbul')->utc(),
        'status' => AppointmentStatus::Confirmed, 'is_walk_in' => false,
    ]);

    $this->actingAs($owner);

    // Select ONLY clinicB (the non-active branch): multiBranch is false, but crossBranch is still
    // true (single selected branch !== active clinic) — the second, easy-to-miss crossBranch path.
    $page = visit('/calendar')
        ->waitForEvent('networkidle')
        ->click('.p-multiselect-label:text-is("Tüm şubeler")')
        ->click('span[data-pc-section="optionlabel"]:text-is("Filyos Şubesi")')
        ->click('h1:text-is("Takvim")')
        ->assertSee('Dr. Bade Yılmaz')
        ->assertPresent('button[aria-pressed="true"]:has-text("Ay")')
        ->assertPresent('button[disabled]:has-text("Gün")');

    // Click the doctor's month-summary chip. Only the doctorFilter narrows here — branchFilter is
    // untouched, so crossBranch stays true and this must not open day view either.
    $page->click('button:has(span:text-is("Dr. Bade Yılmaz"))')
        ->assertNoJavascriptErrors()
        ->assertPresent('button[aria-pressed="true"]:has-text("Ay")')
        ->assertPresent('button[disabled]:has-text("Hafta")')
        ->assertPresent('button[disabled]:has-text("Gün")')
        ->screenshot();
});
