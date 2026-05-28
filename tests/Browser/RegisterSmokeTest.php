<?php

use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Browser smoke — Feature 1.1 (Register). Real Chromium via pest-plugin-browser:
 * the page must mount and render with no JavaScript errors.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(VerticalSeeder::class);
});

it('renders the register page in a real browser without JS errors', function (): void {
    visit('/register')
        ->assertNoJavascriptErrors()
        ->assertSee('Hesap Oluştur')
        ->screenshot();
});
