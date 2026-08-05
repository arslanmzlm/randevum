<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.29 (Tedaviye dosya / before-after foto ekleme). Real Chromium
 * via pest-plugin-browser: the treatments/Process media uploader and treatments/Show media
 * gallery must mount, render page-body content (not just the app shell), and produce no JS
 * errors. Access is doctor/assistant-only (KVKK-min), so both smokes act as a doctor —
 * an owner would never see the media section (v-if gated by `treatments.media.*`).
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main> as
 * an empty comment. The assertSee() calls on in-body labels plus the non-empty <main> script
 * assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Assign a clinic-scoped role.
 */
function tmsRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Clinic + doctor (with a `doctor`-role user) + patient + appointment.
 *
 * @return array{clinic: Clinic, doctorUser: User, doctor: Doctor, patient: Patient, appointment: Appointment}
 */
function tmsSetup(): array
{
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    tmsRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    return compact('clinic', 'doctorUser', 'doctor', 'patient', 'appointment');
}

it('renders the treatment media uploader on the process page with no JS errors', function (): void {
    [
        'clinic' => $clinic,
        'doctorUser' => $doctorUser,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tmsSetup();

    $treatment = Treatment::factory()->draft()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($doctorUser);

    visit("/treatments/{$treatment->id}/process")
        ->assertNoJavascriptErrors()
        // MediaSection's SectionCard title — in-body, only mounted for treatments.media.upload.
        ->assertSee('Dosyalar')
        // MediaUploader's drop zone (also the picker) — proves the uploader itself rendered.
        ->assertSee('Dosyaları buraya sürükleyin veya seçmek için tıklayın')
        // MediaGallery's empty state (no files yet on this fresh Draft treatment).
        ->assertSee('Henüz dosya eklenmedi.')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the treatment media gallery on the show page with an uploaded file and no JS errors', function (): void {
    Queue::fake();
    Storage::fake('media_private');

    [
        'clinic' => $clinic,
        'doctorUser' => $doctorUser,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tmsSetup();

    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    // A document (not an image) avoids relying on queued WebP conversions actually running —
    // it renders as an immediate download row, exercising the gallery's non-image branch.
    $document = UploadedFile::fake()->createWithContent(
        'ayak-raporu.pdf',
        "%PDF-1.4\n%\xC3\xA2\xC3\xA3\xC3\x8F\xC3\x93\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF",
    );

    $treatment->addMedia($document)
        ->withCustomProperties(['caption' => '2. seans öncesi rapor'])
        ->toMediaCollection('treatment_media');

    $this->actingAs($doctorUser);

    visit("/treatments/{$treatment->id}")
        ->assertNoJavascriptErrors()
        // MediaGallery's SectionCard title — only mounted when treatment.media is non-empty.
        ->assertSee('Dosyalar')
        // The uploaded document's name, rendered inside the gallery's download row (Spatie's
        // `media.name` strips the extension — the row also shows size/date, not the caption text).
        ->assertSee('ayak-raporu')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
