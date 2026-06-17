<?php

use App\Models\LegalDocument;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Browser smoke — Feature 1.1 (Register) + signup consent. Real Chromium via
 * pest-plugin-browser: the page must mount and render the consent checkboxes
 * with no JavaScript errors.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(VerticalSeeder::class);

    foreach (ConsentRecorderContract::REGISTRATION_DOCUMENT_TYPES as $type) {
        LegalDocument::factory()->ofType($type)->create();
    }
});

it('renders the register page in a real browser without JS errors', function (): void {
    visit('/register')
        ->assertNoJavascriptErrors()
        ->assertSee('Hesap Oluştur')
        ->assertSee('Veri İşleme Sözleşmesi')
        ->screenshot();
});
