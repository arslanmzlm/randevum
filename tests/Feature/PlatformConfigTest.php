<?php

it('exposes edit/delete windows in seconds', function (): void {
    expect(config('platform.edit_windows.treatment'))->toBe(48 * 3600)
        ->and(config('platform.edit_windows.case'))->toBe(48 * 3600)
        ->and(config('platform.edit_windows.transaction_delete'))->toBe(3600);
});

it('allows appointment hard delete only from Confirmed', function (): void {
    expect(config('platform.appointment.hard_delete_allowed_statuses'))->toBe(['confirmed']);
});

it('defines reminder offsets and window', function (): void {
    expect(config('platform.reminders.offsets'))->toBe([24 * 60, 60])
        ->and(config('platform.reminders.window_minutes'))->toBe(5);
});

it('reads edit windows from the environment', function (): void {
    config()->set('platform.edit_windows.treatment', 3600);

    expect(config('platform.edit_windows.treatment'))->toBe(3600);
});
