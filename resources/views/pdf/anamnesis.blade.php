<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <title>{{ __('health.pdf.title') }} — {{ $patient['fullName'] }}</title>
    <style>
        @page {
            margin: 18mm 16mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
        }

        h1,
        h2 {
            margin: 0;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .header .clinic {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .header .clinic img {
            max-width: 64px;
            max-height: 64px;
        }

        .header .clinic-name {
            font-size: 15px;
            font-weight: 700;
        }

        .header .clinic-meta {
            font-size: 10px;
            color: #4b5563;
        }

        .header .doc-meta {
            text-align: right;
            font-size: 10px;
            color: #4b5563;
        }

        .header .doc-meta h1 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .parties {
            margin-bottom: 18px;
        }

        .party-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 2px;
        }

        .party-value {
            font-size: 12px;
            font-weight: 600;
        }

        section {
            margin-bottom: 18px;
        }

        section h2 {
            font-size: 12px;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        dl {
            margin: 0;
        }

        dt {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
            margin-top: 8px;
        }

        dd {
            margin: 2px 0 0;
            font-size: 11px;
            white-space: pre-wrap;
        }

        .muted {
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="clinic">
            @if ($clinic['logoDataUri'])
                <img src="{{ $clinic['logoDataUri'] }}"
                    alt="{{ $clinic['name'] }}">
            @endif
            <div>
                <div class="clinic-name">{{ $clinic['name'] }}</div>
                <div class="clinic-meta">
                    @if ($clinic['address'])
                        {{ $clinic['address'] }}<br>
                    @endif
                    @if ($clinic['phone'])
                        {{ $clinic['phone'] }}
                    @endif
                </div>
            </div>
        </div>
        <div class="doc-meta">
            <h1>{{ __('health.pdf.title') }}</h1>
            <div>{{ __('health.pdf.date') }}: {{ $documentDate }}</div>
        </div>
    </div>

    <div class="parties">
        <div class="party-label">{{ __('health.pdf.patient') }}</div>
        <div class="party-value">{{ $patient['fullName'] }}</div>
    </div>

    @forelse ($groups as $group)
        <section>
            <h2>{{ $group['title'] }}</h2>
            <dl>
                @foreach ($group['fields'] as $field)
                    <dt>{{ $field['label'] }}</dt>
                    <dd>{{ $field['value'] }}</dd>
                @endforeach
            </dl>
        </section>
    @empty
        <p class="muted">{{ __('health.pdf.empty') }}</p>
    @endforelse
</body>

</html>
