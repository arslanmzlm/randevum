<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('loads vertical-specific config', function (): void {
    expect(config('podiatry.default_slot_duration_minutes'))->toBe(30)
        ->and(config('podiatry.color'))->not->toBeEmpty()
        ->and(config('podiatry.icon'))->not->toBeEmpty();
});

it('loads vertical translations per locale', function (): void {
    expect(__('verticals.podiatry::vertical.name', [], 'en'))->toBe('Podiatry')
        ->and(__('verticals.podiatry::vertical.name', [], 'tr'))->toBe('Podoloji')
        ->and(__('verticals.podiatry::vertical.doctor_label', [], 'tr'))->toBe('Podolog');
});

// The clinical trio is universal (treatments table), not vertical-owned: `services` ships the
// default_* templates for it. The morphTo detail seam stays available but is unused and nullable.
it('keeps the clinical trio on treatments with an optional vertical detail seam', function (): void {
    expect(Schema::hasColumns('treatments', ['complaint', 'diagnosis', 'treatment_process']))->toBeTrue()
        ->and(Schema::hasTable('podiatry_treatment_details'))->toBeFalse();
});
