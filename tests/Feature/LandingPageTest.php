<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('server-renders the landing page with SEO content in the raw HTML', function (): void {
    $this->withoutVite();

    $response = $this->get(route('home'));

    $response->assertOk();
    // Content must be in the server response (not a client-only SPA shell) for crawlers.
    $response->assertSee(__('landing.meta_description'), false);
    $response->assertSee(__('landing.hero_title'), false);
    $response->assertSee(__('landing.features.calendar_title'));
    $response->assertSee(__('landing.steps_title'), false);
    $response->assertSee(__('landing.faq_title'), false);
    $response->assertSee('application/ld+json', false);
    $response->assertSee('FAQPage', false);
});

it('shows the register call to action to guests', function (): void {
    $this->withoutVite();

    $response = $this->get(route('home'));

    $response->assertSee(__('landing.hero_cta_primary'), false);
    $response->assertSee(route('register'), false);
    $response->assertDontSee(__('landing.hero_cta_dashboard'), false);
});

it('shows the dashboard link to an authenticated user', function (): void {
    $this->withoutVite();

    $response = $this->actingAs(User::factory()->create())->get(route('home'));

    $response->assertSee(__('landing.nav_dashboard'), false);
    $response->assertDontSee(__('landing.hero_cta_primary'), false);
});
