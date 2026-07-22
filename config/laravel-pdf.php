<?php

use Spatie\LaravelPdf\Caching\DefaultPdfCache;
use Spatie\LaravelPdf\Encryption\DefaultPdfEncrypter;
use Spatie\LaravelPdf\Jobs\GeneratePdfJob;

// Config files load before Application::detectEnvironment() runs (LoadConfiguration calls it
// AFTER merging all config/*.php), so app()->environment() isn't bound yet here — read
// APP_ENV directly instead.
$isLocalOrTesting = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    /*
     * The default driver to use for PDF generation.
     * Supported: "browsershot", "cloudflare", "dompdf", "gotenberg", "chrome"
     */
    'driver' => env('LARAVEL_PDF_DRIVER', 'browsershot'),

    /*
     * Render caching. When you call `->cache()` on a PDF, the generated
     * content is stored so identical renders are served from the cache.
     *
     * Swap `class` for your own implementation of the PdfCache contract
     * to fully customize how PDFs are keyed, stored, and expired.
     */
    'cache' => [
        'class' => DefaultPdfCache::class,

        /*
         * When set to true, every PDF is cached automatically without having
         * to call `->cache()`. Call `->cache()` or `->dontCache()` on a PDF
         * to override this.
         */
        'automatic' => env('LARAVEL_PDF_CACHE_AUTOMATIC', false),

        /*
         * The cache store to use. Leave null to use the default store.
         */
        'store' => env('LARAVEL_PDF_CACHE_STORE'),

        /*
         * The prefix prepended to every cache key.
         */
        'prefix' => 'laravel-pdf',

        /*
         * The default lifetime in seconds. Leave null to cache forever.
         */
        'ttl' => env('LARAVEL_PDF_CACHE_TTL', 60 * 60 * 24),
    ],

    /*
     * Browsershot driver configuration.
     *
     * Requires the spatie/browsershot package:
     * composer require spatie/browsershot
     */
    'browsershot' => [
        /*
         * Configure the paths to Node.js, npm, Chrome, and other binaries.
         * Leave null to use system defaults or Browsershot's auto-detection.
         *
         * The fallbacks below target DDEV only: the web image already bakes Chromium
         * for Pest browser tests at /ms-playwright (.ddev/web-build/Dockerfile), so this
         * feature reuses it instead of a second Chromium download. They apply ONLY in
         * local/testing so an unconfigured production box falls through to null (auto-
         * detection) instead of a path that doesn't exist there — production Chromium/
         * Node provisioning is a separate ops task; set the LARAVEL_PDF_* env vars there.
         */
        'node_binary' => env('LARAVEL_PDF_NODE_BINARY', $isLocalOrTesting ? '/usr/local/n/bin/node' : null),
        'npm_binary' => env('LARAVEL_PDF_NPM_BINARY', $isLocalOrTesting ? '/usr/local/n/bin/npm' : null),
        'include_path' => env('LARAVEL_PDF_INCLUDE_PATH'),
        'chrome_path' => env('LARAVEL_PDF_CHROME_PATH', $isLocalOrTesting ? (glob('/ms-playwright/chromium-*/chrome-linux64/chrome')[0] ?? null) : null),
        'node_modules_path' => env('LARAVEL_PDF_NODE_MODULES_PATH'),
        'bin_path' => env('LARAVEL_PDF_BIN_PATH'),
        'temp_path' => env('LARAVEL_PDF_TEMP_PATH'),

        /*
         * Other Browsershot configuration options.
         */
        'write_options_to_file' => env('LARAVEL_PDF_WRITE_OPTIONS_TO_FILE', true),
        // DDEV's web container runs Chrome as a non-root user without a setuid sandbox
        // binary, so the zygote sandbox init fails outright without --no-sandbox.
        'no_sandbox' => env('LARAVEL_PDF_NO_SANDBOX', $isLocalOrTesting),
    ],

    /*
     * Cloudflare Browser Rendering driver configuration.
     *
     * Requires a Cloudflare account with the Browser Rendering API enabled.
     * https://developers.cloudflare.com/browser-rendering/
     */
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    ],

    /*
     * Gotenberg driver configuration.
     *
     * Requires a running Gotenberg instance (Docker recommended).
     * https://gotenberg.dev
     */
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://localhost:3000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

    /*
     * DOMPDF driver configuration.
     *
     * Pure PHP PDF generation — no external binaries required.
     * Requires the dompdf/dompdf package:
     * composer require dompdf/dompdf
     */
    'dompdf' => [
        /*
         * Allow DOMPDF to fetch external resources (images, CSS).
         * Set to true if your HTML references remote URLs.
         */
        'is_remote_enabled' => env('LARAVEL_PDF_DOMPDF_REMOTE_ENABLED', false),

        /*
         * The base path for local file access.
         * Defaults to DOMPDF's built-in chroot setting when null.
         */
        'chroot' => env('LARAVEL_PDF_DOMPDF_CHROOT'),
    ],

    /*
    * WeasyPrint driver configuration.
    *
    * Requires the Weasyprint binary and pontedilana/php-weasyprint package:
    * composer require pontedilana/php-weasyprint
    *
    * @see https://doc.courtbouillon.org/weasyprint/stable/first_steps.html
    */
    'weasyprint' => [
        /*
         * Configure the paths to the Weasyprint binary.
         */
        'binary' => env('LARAVEL_PDF_WEASYPRINT_BINARY', 'weasyprint'),

        /*
         * The timeout (default = 10 seconds)
         */
        'timeout' => 10,
    ],

    /*
     * Chrome PHP driver configuration.
     *
     * Requires the Chrome/Chromium executable and chrome-php/chrome package:
     * composer require chrome-php/chrome
     *
     * @see https://github.com/chrome-php/chrome
     */
    'chrome' => [
        'chrome_binary' => env('LARAVEL_PDF_CHROME_BINARY'),

        'no_sandbox' => env('LARAVEL_PDF_CHROME_NO_SANDBOX', false),

        'startup_timeout' => env('LARAVEL_PDF_CHROME_STARTUP_TIMEOUT', 30),

        'timeout' => env('LARAVEL_PDF_CHROME_TIMEOUT', 30000),

        'operation_timeout' => env('LARAVEL_PDF_CHROME_OPERATION_TIMEOUT', 5000),

        'user_data_dir' => env('LARAVEL_PDF_CHROME_USER_DATA_DIR'),

        'custom_flags' => [],

        'env_variables' => [],
    ],

    /*
     * The job class used for queued PDF generation.
     * You can replace this with your own class that extends GeneratePdfJob
     * to customize things like $tries, $timeout, $backoff, or default queue.
     */
    'job' => GeneratePdfJob::class,

    /*
     * The class used to encrypt and decrypt password-protected PDFs.
     *
     * More info in our docs:
     * https://spatie.be/docs/laravel-pdf/v2/basic-usage/protecting-pdfs-with-a-password
     */
    'encrypter' => DefaultPdfEncrypter::class,
];
