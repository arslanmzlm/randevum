<?php

use App\Modules\Core\Support\Toast;

it('flashes a toast to the session', function (): void {
    Toast::success('Kaydedildi', 'Detay');

    $toasts = session('toasts');

    expect($toasts)->toHaveCount(1)
        ->and($toasts[0]['severity'])->toBe('success')
        ->and($toasts[0]['summary'])->toBe('Kaydedildi')
        ->and($toasts[0]['detail'])->toBe('Detay');
});

it('maps warning to the PrimeVue "warn" severity', function (): void {
    Toast::warning('Dikkat');

    expect(session('toasts')[0]['severity'])->toBe('warn');
});

it('accumulates multiple toasts in one request', function (): void {
    Toast::success('A');
    Toast::error('B');

    expect(session('toasts'))->toHaveCount(2)
        ->and(session('toasts')[1]['severity'])->toBe('error');
});

it('shares flashed toasts as flash.toasts on inertia responses', function (): void {
    $this->withSession([
        'toasts' => [
            ['severity' => 'success', 'summary' => 'Merhaba', 'detail' => null, 'life' => 4000],
        ],
    ])->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('flash.toasts', 1)
            ->where('flash.toasts.0.summary', 'Merhaba')
            ->where('flash.toasts.0.severity', 'success')
        );
});

it('shares an empty toast list when nothing is flashed', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('flash.toasts', []));
});
