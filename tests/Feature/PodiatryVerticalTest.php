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

it('owns its treatment-details migration', function (): void {
    expect(Schema::hasTable('podiatry_treatment_details'))->toBeTrue()
        ->and(Schema::hasColumns('podiatry_treatment_details', ['complaint', 'diagnosis', 'treatment_process']))->toBeTrue();
});
