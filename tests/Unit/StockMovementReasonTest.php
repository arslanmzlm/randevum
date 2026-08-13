<?php

use App\Enums\StockMovementReason;

it('gives every reason a direction rule', function (string $reason, ?int $sign): void {
    expect(StockMovementReason::from($reason)->sign())->toBe($sign);
})->with([
    'stock received' => ['stock_in', 1],
    'patient return' => ['patient_return', 1],
    'transfer in' => ['transfer_in', 1],
    'supplier return' => ['supplier_return', -1],
    'wastage' => ['wastage', -1],
    'transfer out' => ['transfer_out', -1],
    'count correction goes either way' => ['count_correction', null],
    'treatment usage' => ['treatment_usage', -1],
    'treatment void restock' => ['treatment_void', 1],
    'opening stock goes either way' => ['initial', null],
]);

it('offers the seven manual reasons in the dialog, and neither retired one', function (): void {
    $manual = array_map(
        static fn (StockMovementReason $reason): string => $reason->value,
        StockMovementReason::manualCases(),
    );

    expect($manual)->toBe([
        'stock_in',
        'patient_return',
        'transfer_in',
        'supplier_return',
        'wastage',
        'transfer_out',
        'count_correction',
    ]);
});

it('keeps count correction out of movement mode, which has no other source for the sign', function (): void {
    $movement = array_map(
        static fn (StockMovementReason $reason): string => $reason->value,
        StockMovementReason::movementModeCases(),
    );

    expect($movement)->not->toContain('count_correction')
        ->and($movement)->toHaveCount(6);

    foreach (StockMovementReason::movementModeCases() as $reason) {
        expect($reason->sign())->not->toBeNull();
    }
});

it('keeps the retired reasons on the enum so old ledger rows still resolve', function (string $reason): void {
    expect(StockMovementReason::tryFrom($reason))->not->toBeNull();
})->with(['manual_adjustment', 'return']);
