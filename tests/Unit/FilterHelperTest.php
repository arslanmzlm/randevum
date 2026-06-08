<?php

use App\Enums\Gender;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
    // Reset any previously merged request params
    request()->replace([]);
});

// ---------------------------------------------------------------------------
// search() — reads filter[search]
// ---------------------------------------------------------------------------

it('search filters records that match a field', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'phone' => '05322222222']);

    request()->replace(['filter' => ['search' => 'Ahmet']]);

    $result = FilterHelper::for(Patient::class)
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->first_name)->toBe('Ahmet');
});

it('search matches across multiple fields (OR logic)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'last_name' => 'Veli', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Veli', 'last_name' => 'Can', 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Hasan', 'last_name' => 'Demir', 'phone' => '05333333333']);

    // "Veli" matches first_name of row 2 AND last_name of row 1
    request()->replace(['filter' => ['search' => 'Veli']]);

    $result = FilterHelper::for(Patient::class)
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(2);
});

it('search ignores empty string and returns all records', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    request()->replace(['filter' => ['search' => '']]);

    $result = FilterHelper::for(Patient::class)
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(3);
});

it('search ignores absent search param and returns all records', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = FilterHelper::for(Patient::class)
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// sort() — reads `sort` (`-` prefix = desc)
// ---------------------------------------------------------------------------

it('sort without a prefix orders ascending', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05322222222']);

    request()->replace(['sort' => 'first_name']);

    $result = FilterHelper::for(Patient::class)->sort('first_name', 'last_name', 'created_at')->paginate();

    expect($result->items()[0]->first_name)->toBe('Ayşe');
});

it('sort with a `-` prefix orders descending', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05322222222']);

    request()->replace(['sort' => '-first_name']);

    $result = FilterHelper::for(Patient::class)->sort('first_name', 'last_name', 'created_at')->paginate();

    expect($result->items()[0]->first_name)->toBe('Zeynep');
});

it('sort falls back to orderByDesc(id) when sort is absent', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    $second = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);

    request()->replace([]);

    $result = FilterHelper::for(Patient::class)->sort('first_name', 'last_name', 'created_at')->paginate();

    // Highest id first
    expect($result->items()[0]->id)->toBe($second->id);
});

it('sort ignores a field not in the allowed list (falls back to id desc)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    $second = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);

    request()->replace(['sort' => 'malicious_field']);

    $result = FilterHelper::for(Patient::class)->sort('first_name', 'last_name', 'created_at')->paginate();

    // Falls back to id desc
    expect($result->items()[0]->id)->toBe($second->id);
});

// ---------------------------------------------------------------------------
// exact() — reads filter[<column>]; `_id` columns get a numeric guard
// ---------------------------------------------------------------------------

it('exact matches a plain column value from the request', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'male', 'phone' => '05322222222']);

    request()->replace(['filter' => ['gender' => 'female']]);

    expect(FilterHelper::for(Patient::class)->exact('gender')->paginate()->total())->toBe(1);
});

it('exact on an _id column matches when numeric and is ignored when not', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $user = User::factory()->create();
    Patient::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id, 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'user_id' => null, 'phone' => '05322222222']);

    request()->replace(['filter' => ['user_id' => (string) $user->id]]);
    expect(FilterHelper::for(Patient::class)->exact('user_id')->paginate()->total())->toBe(1);

    // Non-numeric on an _id column → ignored (guards against a bigint SQL error).
    request()->replace(['filter' => ['user_id' => 'abc']]);
    expect(FilterHelper::for(Patient::class)->exact('user_id')->paginate()->total())->toBe(2);
});

it('exact skips empty or absent params', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    expect(FilterHelper::for(Patient::class)->exact('gender', 'user_id')->paginate()->total())->toBe(3);
});

// ---------------------------------------------------------------------------
// paginate() — flat per_page
// ---------------------------------------------------------------------------

it('paginate respects per_page from the request', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(10)->create(['clinic_id' => $clinic->id]);

    request()->replace(['per_page' => '3']);

    $result = FilterHelper::for(Patient::class)->sort('first_name')->paginate();

    expect($result->perPage())->toBe(3)
        ->and($result->count())->toBe(3)
        ->and($result->total())->toBe(10);
});

it('paginate uses the default per_page when not in the request', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(5)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = FilterHelper::for(Patient::class)->sort('first_name')->paginate(20);

    expect($result->perPage())->toBe(20);
});

// ---------------------------------------------------------------------------
// boolean() — reads filter[<column>]
// ---------------------------------------------------------------------------

it('boolean filters to true / false / all across the three states', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'is_legacy' => false, 'phone' => '05322222222']);

    request()->replace(['filter' => ['is_legacy' => '1']]);
    expect(FilterHelper::for(Patient::class)->boolean('is_legacy')->paginate()->total())->toBe(1);

    request()->replace(['filter' => ['is_legacy' => '0']]);
    expect(FilterHelper::for(Patient::class)->boolean('is_legacy')->paginate()->total())->toBe(1);

    request()->replace([]);
    expect(FilterHelper::for(Patient::class)->boolean('is_legacy')->paginate()->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// enum() — reads filter[<column>]
// ---------------------------------------------------------------------------

it('enum filters by a valid backed-enum value and ignores an invalid one', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'male', 'phone' => '05322222222']);

    request()->replace(['filter' => ['gender' => 'female']]);
    expect(FilterHelper::for(Patient::class)->enum(['gender' => Gender::class])->paginate()->total())->toBe(1);

    // Invalid enum value → filter ignored, all rows returned.
    request()->replace(['filter' => ['gender' => 'not-a-gender']]);
    expect(FilterHelper::for(Patient::class)->enum(['gender' => Gender::class])->paginate()->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// enumMultiple() — reads filter[<column>] (csv)
// ---------------------------------------------------------------------------

it('enumMultiple filters by a comma-separated list and drops invalid members', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'male', 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'other', 'phone' => '05333333333']);

    request()->replace(['filter' => ['gender' => 'female,male,bogus']]);

    $result = FilterHelper::for(Patient::class)->enumMultiple(['gender' => Gender::class])->paginate();

    expect($result->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// date() — reads filter[<column>]
// ---------------------------------------------------------------------------

it('date matches rows on the given day', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'birth_date' => '1990-05-20', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'birth_date' => '1985-12-01', 'phone' => '05322222222']);

    request()->replace(['filter' => ['birth_date' => '1990-05-20']]);

    $result = FilterHelper::for(Patient::class)->date('birth_date')->paginate();

    expect($result->total())->toBe(1);
});

// ---------------------------------------------------------------------------
// dateRange() — reads filter[start_date] / filter[end_date]
// ---------------------------------------------------------------------------

it('dateRange filters inclusively between the start and end days', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'created_at' => '2026-01-10 12:00:00', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'created_at' => '2026-02-15 12:00:00', 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'created_at' => '2026-03-20 12:00:00', 'phone' => '05333333333']);

    request()->replace(['filter' => ['start_date' => '2026-01-01', 'end_date' => '2026-02-28']]);

    $result = FilterHelper::for(Patient::class)
        ->dateRange('created_at', timezone: 'UTC')
        ->paginate();

    expect($result->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// trashed() — reads filter[trashed]
// ---------------------------------------------------------------------------

it('trashed toggles soft-deleted visibility (default / with / only)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    $deleted = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);
    $deleted->delete();

    request()->replace([]);
    expect(FilterHelper::for(Patient::class)->trashed()->paginate()->total())->toBe(1);

    request()->replace(['filter' => ['trashed' => 'with']]);
    expect(FilterHelper::for(Patient::class)->trashed()->paginate()->total())->toBe(2);

    request()->replace(['filter' => ['trashed' => 'only']]);
    expect(FilterHelper::for(Patient::class)->trashed()->paginate()->total())->toBe(1);
});

// ---------------------------------------------------------------------------
// get()
// ---------------------------------------------------------------------------

it('get returns the filtered rows as a collection without pagination', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'phone' => '05322222222']);

    request()->replace(['filter' => ['search' => 'Ahmet']]);

    $result = FilterHelper::for(Patient::class)->search('first_name', 'last_name')->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->first_name)->toBe('Ahmet');
});

// ---------------------------------------------------------------------------
// searchRelation() — reads filter[search], searches on a related model
// ---------------------------------------------------------------------------

it('searchRelation matches records whose related model has a matching field', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $matchPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'phone' => '05311111111']);
    $noMatchPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'phone' => '05322222222']);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $matchPatient->id, 'doctor_id' => $doctor->id]);
    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $noMatchPatient->id, 'doctor_id' => $doctor->id]);

    request()->replace(['filter' => ['search' => 'Ahmet']]);

    $result = FilterHelper::for(Appointment::class)
        ->searchRelation('patient', 'first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->patient_id)->toBe($matchPatient->id);
});

it('searchRelation with no match returns an empty result', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'phone' => '05311111111']);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

    request()->replace(['filter' => ['search' => 'Xxxxxxxxxx']]);

    $result = FilterHelper::for(Appointment::class)
        ->searchRelation('patient', 'first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(0);
});

it('searchRelation ignores empty search and returns all records', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    Appointment::factory()->count(3)->create(['clinic_id' => $clinic->id, 'doctor_id' => $doctor->id]);

    request()->replace(['filter' => ['search' => '']]);

    $result = FilterHelper::for(Appointment::class)
        ->searchRelation('patient', 'first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(3);
});

it('searchRelation matches across multiple fields on the relation (OR logic)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    // "Veli" matches first_name of patient1 AND last_name of patient2
    $patient1 = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Veli', 'last_name' => 'Can', 'phone' => '05311111111']);
    $patient2 = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'last_name' => 'Veli', 'phone' => '05322222222']);
    $patient3 = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Hasan', 'last_name' => 'Demir', 'phone' => '05333333333']);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $patient1->id, 'doctor_id' => $doctor->id]);
    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $patient2->id, 'doctor_id' => $doctor->id]);
    Appointment::factory()->create(['clinic_id' => $clinic->id, 'patient_id' => $patient3->id, 'doctor_id' => $doctor->id]);

    request()->replace(['filter' => ['search' => 'Veli']]);

    $result = FilterHelper::for(Appointment::class)
        ->searchRelation('patient', 'first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(2);
});
