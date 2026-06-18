@php
    $brand = config('app.name');
    $features = [
        [
            'icon' =>
                '<path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M8 15h2v2h-2z" />',
            'title' => __('landing.features.calendar_title'),
            'text' => __('landing.features.calendar_text'),
        ],
        [
            'icon' =>
                '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />',
            'title' => __('landing.features.patients_title'),
            'text' => __('landing.features.patients_text'),
        ],
        [
            'icon' =>
                '<path d="M6 4h-1a2 2 0 0 0 -2 2v3.5a5.5 5.5 0 0 0 11 0v-3.5a2 2 0 0 0 -2 -2h-1" /><path d="M8 15a6 6 0 1 0 12 0v-3" /><path d="M11 3v2" /><path d="M6 3v2" /><path d="M20 10m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />',
            'title' => __('landing.features.treatments_title'),
            'text' => __('landing.features.treatments_text'),
        ],
        [
            'icon' =>
                '<path d="M3 20l1.3 -3.9a9 8 0 1 1 3.4 2.9l-4.7 1" /><path d="M12 12v.01" /><path d="M8 12v.01" /><path d="M16 12v.01" />',
            'title' => __('landing.features.sms_title'),
            'text' => __('landing.features.sms_text'),
        ],
        [
            'icon' =>
                '<path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M14 11h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5" /><path d="M12 17v1m0 -8v1" />',
            'title' => __('landing.features.revenue_title'),
            'text' => __('landing.features.revenue_text'),
        ],
        [
            'icon' =>
                '<path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06" /><path d="M15 19l2 2l4 -4" />',
            'title' => __('landing.features.roles_title'),
            'text' => __('landing.features.roles_text'),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">

    <title>{{ __('landing.meta_title') }}</title>
    <meta name="description" content="{{ __('landing.meta_description') }}">
    <link rel="canonical" href="{{ url('/') }}">
    <meta name="robots" content="index, follow">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:locale"
        content="{{ str_replace('-', '_', str_replace('_', '-', app()->getLocale())) }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:title" content="{{ __('landing.meta_title') }}">
    <meta property="og:description"
        content="{{ __('landing.meta_description') }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ __('landing.meta_title') }}">
    <meta name="twitter:description"
        content="{{ __('landing.meta_description') }}">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @fonts
    @vite(['resources/css/app.css'])

    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $brand,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => __('landing.meta_description'),
            'url' => url('/'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>

    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ], (array) __('landing.faq')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
</head>

<body class="font-sans antialiased">
    <div class="min-h-screen bg-white text-neutral-900">
        <header
            class="sticky top-0 z-30 border-b border-neutral-200 bg-white/90 backdrop-blur">
            <div
                class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <span
                    class="text-brand text-xl font-semibold tracking-tight">{{ $brand }}</span>

                <nav class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}"
                            class="bg-brand hover:bg-brand-dark rounded-md px-4 py-2 text-sm font-medium text-white transition-colors">
                            {{ __('landing.nav_dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}"
                            class="rounded-md px-4 py-2 text-sm font-medium text-neutral-600 transition-colors hover:text-neutral-900">
                            {{ __('landing.nav_login') }}
                        </a>
                        <a href="{{ route('register') }}"
                            class="bg-brand hover:bg-brand-dark rounded-md px-4 py-2 text-sm font-medium text-white transition-colors">
                            {{ __('landing.nav_register') }}
                        </a>
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            <section
                class="mx-auto max-w-6xl px-6 pb-16 pt-10 lg:grid lg:grid-cols-2 lg:items-center lg:gap-12 lg:pb-24 lg:pt-20">
                <div>
                    <span
                        class="bg-brand/10 text-brand-dark inline-flex items-center rounded-full px-3 py-1 text-xs font-medium">
                        {{ __('landing.hero_badge') }}
                    </span>
                    <h1
                        class="mt-5 text-balance text-4xl font-bold tracking-tight lg:text-5xl">
                        {{ __('landing.hero_title') }}
                    </h1>
                    <p class="mt-5 max-w-xl text-lg text-neutral-600">
                        {{ __('landing.hero_subtitle') }}
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        @auth
                            <a href="{{ route('dashboard') }}"
                                class="bg-brand hover:bg-brand-dark rounded-lg px-6 py-3 text-sm font-semibold text-white transition-colors">
                                {{ __('landing.hero_cta_dashboard') }}
                            </a>
                        @else
                            <a href="{{ route('register') }}"
                                class="bg-brand hover:bg-brand-dark rounded-lg px-6 py-3 text-sm font-semibold text-white transition-colors">
                                {{ __('landing.hero_cta_primary') }}
                            </a>
                            <a href="{{ route('login') }}"
                                class="rounded-lg border border-neutral-300 px-6 py-3 text-sm font-semibold text-neutral-700 transition-colors hover:border-neutral-400 hover:bg-neutral-50">
                                {{ __('landing.hero_cta_secondary') }}
                            </a>
                        @endauth
                    </div>
                </div>

                <div class="mt-12 lg:mt-0">
                    <div
                        class="rounded-2xl border border-neutral-200 bg-neutral-50 p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold">
                                {{ __('landing.preview_today') }}</p>
                            <span
                                class="bg-brand/10 text-brand-dark rounded-full px-2.5 py-1 text-xs font-medium">
                                {{ __('landing.preview_appointments', ['count' => 3]) }}
                            </span>
                        </div>
                        <ul class="mt-4 space-y-2">
                            @foreach ((array) __('landing.preview_slots') as $slot)
                                <li
                                    class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-white px-4 py-3 text-sm">
                                    <span
                                        class="bg-brand h-2.5 w-2.5 shrink-0 rounded-full"></span>
                                    <span
                                        class="text-neutral-700">{{ $slot }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>

            <section
                class="border-t border-neutral-200 bg-neutral-50 py-16 lg:py-24">
                <div class="mx-auto max-w-6xl px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2
                            class="text-balance text-3xl font-bold tracking-tight">
                            {{ __('landing.features_title') }}
                        </h2>
                        <p class="mt-4 text-neutral-600">
                            {{ __('landing.features_subtitle') }}</p>
                    </div>

                    <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($features as $feature)
                            <div
                                class="rounded-xl border border-neutral-200 bg-white p-6 transition-shadow hover:shadow-md">
                                <span
                                    class="bg-brand/10 text-brand-dark flex h-11 w-11 items-center justify-center rounded-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                        width="22" height="22"
                                        viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.75"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true">{!! $feature['icon'] !!}</svg>
                                </span>
                                <h3 class="mt-4 text-base font-semibold">
                                    {{ $feature['title'] }}</h3>
                                <p class="mt-2 text-sm text-neutral-600">
                                    {{ $feature['text'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="py-16 lg:py-24">
                <div class="mx-auto max-w-6xl px-6">
                    <div class="mx-auto max-w-2xl text-center">
                        <h2
                            class="text-balance text-3xl font-bold tracking-tight">
                            {{ __('landing.steps_title') }}
                        </h2>
                        <p class="mt-4 text-neutral-600">
                            {{ __('landing.steps_subtitle') }}</p>
                    </div>

                    <div class="mt-12 grid gap-8 sm:grid-cols-3">
                        @foreach ((array) __('landing.steps') as $index => $step)
                            <div class="text-center sm:text-left">
                                <span
                                    class="bg-brand/10 text-brand-dark flex h-11 w-11 items-center justify-center rounded-full text-lg font-bold max-sm:mx-auto">
                                    {{ $index + 1 }}
                                </span>
                                <h3 class="mt-4 text-base font-semibold">
                                    {{ $step['title'] }}</h3>
                                <p class="mt-2 text-sm text-neutral-600">
                                    {{ $step['text'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section
                class="border-t border-neutral-200 bg-neutral-50 py-16 lg:py-24">
                <div class="mx-auto max-w-3xl px-6">
                    <h2
                        class="text-balance text-center text-3xl font-bold tracking-tight">
                        {{ __('landing.faq_title') }}
                    </h2>

                    <div class="mt-10 space-y-3">
                        @foreach ((array) __('landing.faq') as $item)
                            <details
                                class="group rounded-xl border border-neutral-200 bg-white px-5 py-4">
                                <summary
                                    class="flex cursor-pointer items-center justify-between gap-4 text-base font-medium marker:content-none">
                                    {{ $item['q'] }}
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                        width="20" height="20"
                                        viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="text-brand-dark shrink-0 transition-transform group-open:rotate-180"
                                        aria-hidden="true">
                                        <path d="M6 9l6 6l6 -6" />
                                    </svg>
                                </summary>
                                <p class="mt-3 text-sm text-neutral-600">
                                    {{ $item['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            @guest
                <section class="mx-auto max-w-6xl px-6 py-16 lg:py-24">
                    <div
                        class="bg-brand rounded-2xl px-8 py-12 text-center lg:px-16 lg:py-16">
                        <h2
                            class="text-balance text-3xl font-bold tracking-tight text-white">
                            {{ __('landing.cta_title') }}
                        </h2>
                        <p class="mx-auto mt-4 max-w-xl text-white/80">
                            {{ __('landing.cta_text') }}</p>
                        <a href="{{ route('register') }}"
                            class="text-brand-dark mt-8 inline-block rounded-lg bg-white px-6 py-3 text-sm font-semibold transition-colors hover:bg-white/90">
                            {{ __('landing.cta_button') }}
                        </a>
                    </div>
                </section>
            @endguest
        </main>

        <footer class="border-t border-neutral-200">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-6 py-8 sm:flex-row">
                <span
                    class="text-brand text-lg font-semibold">{{ $brand }}</span>
                <p class="text-sm text-neutral-500">
                    {{ __('landing.footer_rights', ['year' => now()->year]) }}
                </p>
            </div>
        </footer>
    </div>
</body>

</html>
