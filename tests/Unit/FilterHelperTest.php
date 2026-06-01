<?php

use App\Models\Clinic;
use App\Models\Patient;
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
// search()
// ---------------------------------------------------------------------------

it('search filters records that match a field', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'phone' => '05322222222']);

    request()->replace(['search' => 'Ahmet']);

    $result = FilterHelper::query(Patient::query())
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
    request()->replace(['search' => 'Veli']);

    $result = FilterHelper::query(Patient::query())
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(2);
});

it('search ignores empty string and returns all records', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    request()->replace(['search' => '']);

    $result = FilterHelper::query(Patient::query())
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(3);
});

it('search ignores absent search param and returns all records', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())
        ->search('first_name', 'last_name', 'phone')
        ->paginate();

    expect($result->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// sort()
// ---------------------------------------------------------------------------

it('sort with sort_order=1 orders ascending', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05322222222']);

    request()->replace(['sort_field' => 'first_name', 'sort_order' => '1']);

    $result = FilterHelper::query(Patient::query())->sort('first_name', 'last_name', 'created_at')->paginate();

    expect($result->items()[0]->first_name)->toBe('Ayşe');
});

it('sort with sort_order=-1 orders descending', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05322222222']);

    request()->replace(['sort_field' => 'first_name', 'sort_order' => '-1']);

    $result = FilterHelper::query(Patient::query())->sort('first_name', 'last_name', 'created_at')->paginate();

    expect($result->items()[0]->first_name)->toBe('Zeynep');
});

it('sort falls back to orderByDesc(id) when sort_field is absent', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $first = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    $second = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())->sort('first_name', 'last_name', 'created_at')->paginate();

    // Highest id first
    expect($result->items()[0]->id)->toBe($second->id);
});

it('sort ignores a sort_field not in the allowed list (falls back to id desc)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $first = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    $second = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);

    request()->replace(['sort_field' => 'malicious_field', 'sort_order' => '1']);

    $result = FilterHelper::query(Patient::query())->sort('first_name', 'last_name', 'created_at')->paginate();

    // Falls back to id desc
    expect($result->items()[0]->id)->toBe($second->id);
});

// ---------------------------------------------------------------------------
// filter()
// ---------------------------------------------------------------------------

it('filter applies exact-match where for non-null values', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())
        ->filter(['is_legacy' => true])
        ->paginate();

    expect($result->total())->toBe(1);
});

it('filter skips null values (does not filter on null fields)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())
        ->filter(['is_legacy' => null, 'gender' => null])
        ->paginate();

    expect($result->total())->toBe(3);
});

it('filter can combine multiple non-null filters (AND logic)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05311111111']);
    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'gender' => 'male', 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05333333333']);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())
        ->filter(['is_legacy' => true, 'gender' => 'female'])
        ->paginate();

    expect($result->total())->toBe(1);
});

// ---------------------------------------------------------------------------
// paginate()
// ---------------------------------------------------------------------------

it('paginate respects per_page from the request', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(10)->create(['clinic_id' => $clinic->id]);

    request()->replace(['per_page' => '3']);

    $result = FilterHelper::query(Patient::query())->sort('first_name')->paginate();

    expect($result->perPage())->toBe(3)
        ->and($result->count())->toBe(3)
        ->and($result->total())->toBe(10);
});

it('paginate uses the default per_page when not in the request', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(5)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = FilterHelper::query(Patient::query())->sort('first_name')->paginate(20);

    expect($result->perPage())->toBe(20);
});
