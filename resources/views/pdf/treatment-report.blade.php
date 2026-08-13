<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <title>{{ __('treatment.report.title') }} — {{ $documentNumber }}</title>
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

        table {
            width: 100%;
            border-collapse: collapse;
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
            display: flex;
            gap: 24px;
            margin-bottom: 18px;
        }

        .parties .party {
            flex: 1;
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

        .deleted-badge {
            font-size: 9px;
            font-weight: 700;
            color: #b91c1c;
            margin-left: 4px;
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

        .items th,
        .items td {
            padding: 5px 6px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        .items th {
            background: #f3f4f6;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #4b5563;
        }

        .items td.num,
        .items th.num {
            text-align: right;
        }

        .totals {
            width: 260px;
            margin-left: auto;
        }

        .totals td {
            padding: 3px 0;
        }

        .totals td.num {
            text-align: right;
        }

        .totals .grand-total td {
            font-size: 13px;
            font-weight: 700;
            border-top: 1px solid #1f2937;
            padding-top: 6px;
        }

        .balance-row td {
            font-weight: 700;
        }

        .clinical dl {
            margin: 0;
        }

        .clinical dt {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
            margin-top: 8px;
        }

        .clinical dd {
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
            <h1>{{ __('treatment.report.title') }}</h1>
            <div>{{ __('treatment.report.document_no') }}:
                {{ $documentNumber }}</div>
            <div>{{ __('treatment.report.date') }}: {{ $documentDate }}</div>
        </div>
    </div>

    <div class="parties">
        <div class="party">
            <div class="party-label">{{ __('treatment.report.patient') }}</div>
            <div class="party-value">
                {{ $patient['fullName'] }}
                @if ($patient['deleted'])
                    <span
                        class="deleted-badge">{{ __('treatment.report.deleted_record') }}</span>
                @endif
            </div>
        </div>
        <div class="party">
            <div class="party-label">{{ __('treatment.report.doctor') }}</div>
            <div class="party-value">
                {{ $doctor['displayName'] }}
                @if ($doctor['deleted'])
                    <span
                        class="deleted-badge">{{ __('treatment.report.deleted_record') }}</span>
                @endif
            </div>
        </div>
    </div>

    @if (count($serviceLines) > 0)
        <section>
            <h2>{{ __('treatment.report.services') }}</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th>{{ __('treatment.report.line_item') }}</th>
                        <th class="num">
                            {{ __('treatment.report.quantity') }}</th>
                        <th class="num">
                            {{ __('treatment.report.unit_price') }}</th>
                        <th class="num">
                            {{ __('treatment.report.discount') }}</th>
                        <th class="num">
                            {{ __('treatment.report.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceLines as $line)
                        <tr>
                            <td>{{ $line['name'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td class="num">{{ $line['unitPrice'] }}</td>
                            <td class="num">{{ $line['discount'] }}</td>
                            <td class="num">{{ $line['subtotal'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if (count($productLines) > 0)
        <section>
            <h2>{{ __('treatment.report.products') }}</h2>
            <table class="items">
                <thead>
                    <tr>
                        <th>{{ __('treatment.report.line_item') }}</th>
                        <th class="num">
                            {{ __('treatment.report.quantity') }}</th>
                        <th class="num">
                            {{ __('treatment.report.unit_price') }}</th>
                        <th class="num">
                            {{ __('treatment.report.discount') }}</th>
                        <th class="num">
                            {{ __('treatment.report.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($productLines as $line)
                        <tr>
                            <td>{{ $line['name'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td class="num">{{ $line['unitPrice'] }}</td>
                            <td class="num">{{ $line['discount'] }}</td>
                            <td class="num">{{ $line['subtotal'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <table class="totals">
        <tr>
            <td>{{ __('treatment.report.subtotal') }}</td>
            <td class="num">{{ $subtotalAmount }}</td>
        </tr>
        <tr>
            <td>{{ __('treatment.report.discount') }}</td>
            <td class="num">{{ $discountAmount }}</td>
        </tr>
        <tr class="grand-total">
            <td>{{ __('treatment.report.total') }}</td>
            <td class="num">{{ $totalAmount }}</td>
        </tr>
        <tr>
            <td>{{ __('treatment.report.paid_total') }}</td>
            <td class="num">{{ $paidTotal }}</td>
        </tr>
        <tr class="balance-row">
            <td>{{ __('treatment.report.remaining_balance') }}</td>
            <td class="num">{{ $remainingBalance }}</td>
        </tr>
    </table>

    @if ($showPayments)
        <section>
            <h2>{{ __('treatment.report.payments') }}</h2>
            @if (count($payments) > 0)
                <table class="items">
                    <thead>
                        <tr>
                            <th>{{ __('treatment.report.payment_date') }}</th>
                            <th>{{ __('treatment.report.payment_method') }}
                            </th>
                            <th class="num">
                                {{ __('treatment.report.payment_amount') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ $payment['date'] }}</td>
                                <td>{{ $payment['method'] }}</td>
                                <td class="num">{{ $payment['amount'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">{{ __('treatment.report.no_payments') }}</p>
            @endif
        </section>
    @endif

    @if (
        $notes ||
            $details['complaint'] ||
            $details['diagnosis'] ||
            $details['treatmentProcess']
    )
        <section class="clinical">
            <h2>{{ __('treatment.report.clinical_info') }}</h2>
            <dl>
                @if ($details['complaint'])
                    <dt>{{ __('treatment.fields.complaint') }}</dt>
                    <dd>{{ $details['complaint'] }}</dd>
                @endif
                @if ($details['diagnosis'])
                    <dt>{{ __('treatment.fields.diagnosis') }}</dt>
                    <dd>{{ $details['diagnosis'] }}</dd>
                @endif
                @if ($details['treatmentProcess'])
                    <dt>{{ __('treatment.fields.treatment_process') }}</dt>
                    <dd>{{ $details['treatmentProcess'] }}</dd>
                @endif
                @if ($notes)
                    <dt>{{ __('treatment.fields.notes') }}</dt>
                    <dd>{{ $notes }}</dd>
                @endif
            </dl>
        </section>
    @endif
</body>

</html>
