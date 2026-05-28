<?php

/**
 * Browser smoke — Feature 1.2 (Login). Real Chromium via pest-plugin-browser:
 * the page must mount and render with no JavaScript errors.
 */
it('renders the login page in a real browser without JS errors', function (): void {
    visit('/login')
        ->assertNoJavascriptErrors()
        ->assertSee('Giriş Yap')
        ->screenshot();
});
