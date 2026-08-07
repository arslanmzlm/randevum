<?php

use App\Models\Clinic;
use App\Models\FollowUpType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.65 (Takip kayıtları). Real Chromium via
 * pest-plugin-browser: the follow-up-types index page must mount, render
 * page-body content (not just the app shell), and produce no JS errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors()
 * only catches *uncaught* window errors. Vue swallows component setup/render
 * errors and leaves <main> as an empty comment. The assertSee() calls on
 * in-body labels plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the follow-up-types index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    FollowUpType::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Kontrol Araması',
        'is_system' => false,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/follow-up-types')
        ->assertNoJavascriptErrors()
        // Seeded type name rendered inside the DataTable row.
        ->assertSee('Kontrol Araması')
        // DataTable column header — page body content, not app shell.
        ->assertSee('Durum')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
