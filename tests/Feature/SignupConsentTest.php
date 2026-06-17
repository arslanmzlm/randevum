<?php

use App\Enums\LegalDocumentType;
use App\Models\Clinic;
use App\Models\Consent;
use App\Models\LegalDocument;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use Database\Seeders\CountrySeeder;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->vertical = Vertical::where('is_active', true)->first();
});

function seedPlatformLegalDocs(): void
{
    $author = User::factory()->create();
    foreach (ConsentRecorderContract::REGISTRATION_DOCUMENT_TYPES as $type) {
        LegalDocument::factory()->ofType($type)->create(['created_by' => $author->id]);
    }
}

function signupConsentPayload(int $verticalId): array
{
    return [
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
        'email' => 'ali@example.com',
        'password' => 'password12345',
        'password_confirmation' => 'password12345',
        'vertical_id' => $verticalId,
        'clinic_name' => 'Onay Klinik',
        'terms' => true,
        'dpa' => true,
    ];
}

// ---------------------------------------------------------------------------
// Register page exposes the platform legal documents
// ---------------------------------------------------------------------------

it('exposes the three active platform legal documents on the register page', function (): void {
    seedPlatformLegalDocs();

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->has('legalDocuments.terms_of_service.content')
            ->has('legalDocuments.privacy_policy.content')
            ->has('legalDocuments.data_processing_agreement.title')
            ->has('legalDocuments.data_processing_agreement.version')
        );
});

it('only exposes active platform documents, not clinic or inactive ones', function (): void {
    seedPlatformLegalDocs();
    LegalDocument::factory()->ofType(LegalDocumentType::TermsOfService)->inactive()->create();

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('legalDocuments', 3)
        );
});

// ---------------------------------------------------------------------------
// Successful registration records one consent per platform document
// ---------------------------------------------------------------------------

it('records one user consent per platform document on successful registration', function (): void {
    seedPlatformLegalDocs();

    $this->withHeader('User-Agent', 'PestAgent')
        ->post(route('register.store'), signupConsentPayload($this->vertical->id))
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'ali@example.com')->first();

    expect(Consent::count())->toBe(3)
        ->and(Consent::where('consentable_type', 'user')->where('consentable_id', $user->id)->count())->toBe(3);
});

it('binds each consent to an active platform document with a user morph and null clinic', function (): void {
    seedPlatformLegalDocs();

    $this->post(route('register.store'), signupConsentPayload($this->vertical->id));

    $user = User::where('email', 'ali@example.com')->first();
    $docIds = LegalDocument::whereNull('clinic_id')->where('is_active', true)->pluck('id')->sort()->values();

    $consents = Consent::where('consentable_id', $user->id)->get();

    expect($consents)->toHaveCount(3);

    $consents->each(function (Consent $consent) use ($user): void {
        expect($consent->consentable_type)->toBe('user')
            ->and($consent->clinic_id)->toBeNull()
            ->and($consent->accepted_by_user_id)->toBe($user->id)
            ->and($consent->accepted_at)->not->toBeNull();
    });

    expect($consents->pluck('legal_document_id')->sort()->values()->all())
        ->toBe($docIds->all());
});

it('captures the request ip and user agent on the consent rows', function (): void {
    seedPlatformLegalDocs();

    $this->withHeader('User-Agent', 'PestAgent')
        ->post(route('register.store'), signupConsentPayload($this->vertical->id));

    $consent = Consent::first();

    expect($consent->ip_address)->toBe('127.0.0.1')
        ->and($consent->user_agent)->toBe('PestAgent');
});

it('resolves the user consentable morph back to the User model', function (): void {
    seedPlatformLegalDocs();

    $this->post(route('register.store'), signupConsentPayload($this->vertical->id));

    $user = User::where('email', 'ali@example.com')->first();

    expect(Consent::first()->consentable)->toBeInstanceOf(User::class)
        ->and($user->consents()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Validation rolls everything back — no user, no consent
// ---------------------------------------------------------------------------

it('writes no user and no consent when the dpa checkbox is unaccepted', function (): void {
    seedPlatformLegalDocs();

    $payload = signupConsentPayload($this->vertical->id);
    $payload['dpa'] = false;

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors('dpa');

    expect(User::where('email', 'ali@example.com')->exists())->toBeFalse()
        ->and(Consent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// LegalDocumentSeeder — platform docs, idempotent, superadmin-gated
// ---------------------------------------------------------------------------

it('seeds exactly three active platform documents and is idempotent', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $this->seed(LegalDocumentSeeder::class);
    $this->seed(LegalDocumentSeeder::class);

    $docs = LegalDocument::whereNull('clinic_id')->where('is_active', true)->get();

    expect($docs)->toHaveCount(3)
        ->and($docs->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all())
        ->toBe([
            LegalDocumentType::DataProcessingAgreement->value,
            LegalDocumentType::PrivacyPolicy->value,
            LegalDocumentType::TermsOfService->value,
        ])
        ->and($docs->every(fn (LegalDocument $d): bool => $d->created_by === $superadmin->id))->toBeTrue();
});

it('seeds the platform docs even when a clinic team context is active', function (): void {
    // DemoSeeder runs before LegalDocumentSeeder and leaves the Spatie team context set to a
    // clinic; the superadmin resolution must still find the global (clinic_id null) assignment.
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $clinic = Clinic::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    $this->seed(LegalDocumentSeeder::class);

    expect(LegalDocument::whereNull('clinic_id')->where('is_active', true)->count())->toBe(3);
});

it('seeds nothing when no superadmin user exists', function (): void {
    $this->seed(LegalDocumentSeeder::class);

    expect(LegalDocument::count())->toBe(0);
});
