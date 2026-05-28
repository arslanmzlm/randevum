<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.26 (Settings). Real Chromium via pest-plugin-browser:
 * the page must mount and render with no JavaScript errors for an authenticated user.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the settings page in a real browser without JS errors', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/settings')
        ->assertNoJavascriptErrors()
        ->assertSee('Profil Bilgileri')
        ->screenshot();
});
