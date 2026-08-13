<?php

namespace App\Modules\Medical\Services;

use App\Models\Clinic;
use App\Models\Treatment;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Assembles the treatment-summary PDF (patient's own all-in-one record): line items,
 * totals, payments/balance (via the Billing seam), and the vertical clinical fields.
 * No storage — every call renders a fresh PdfBuilder (see the owner brief: not a
 * critical document, on-demand only).
 */
class TreatmentReportService
{
    public function __construct(
        private BalanceReaderContract $balanceReader,
    ) {}

    /**
     * @param  bool  $showPayments  Itemized payment list is privileged (transactions.viewAny);
     *                              paid total / remaining balance are always shown.
     */
    public function build(Treatment $treatment, bool $showPayments): PdfBuilder
    {
        $treatment->loadMissing([
            'clinic.city',
            // withTrashed(): the treatment outlives a soft-deleted patient (treatments are
            // never deleted) — a document requested long after "hasta sil" must still render.
            'patient' => fn ($q) => $q->withTrashed(),
            'doctor' => fn ($q) => $q->withTrashed()->with('user'),
            'serviceLines.service',
            'productLines.product',
        ]);

        $clinic = $treatment->clinic;

        $data = $this->buildViewData($treatment, $clinic, $showPayments);

        return Pdf::view('pdf.treatment-report', $data)
            ->format(Format::A4)
            ->name($data['documentNumber'].'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(Treatment $treatment, Clinic $clinic, bool $showPayments): array
    {
        $lang = strtolower(explode('_', $clinic->locale)[0]);

        $documentDate = ($treatment->completed_at ?? $treatment->created_at)
            ->copy()
            ->setTimezone($clinic->timezone);

        $transactions = $this->balanceReader->transactionsForTreatment($treatment->id);

        $paidTotal = array_reduce(
            $transactions,
            fn (string $carry, array $tx): string => bcadd($carry, (string) $tx['amount'], 2),
            '0.00',
        );

        // Clamp to 0 to match the Show page (Math.max(0, total − paid)); a net overpay
        // must never print a negative "Kalan Bakiye" where the UI shows 0.00.
        $rawRemaining = bcsub((string) $treatment->total_amount, $paidTotal, 2);
        $remainingBalance = bccomp($rawRemaining, '0', 2) < 0 ? '0.00' : $rawRemaining;

        return [
            'documentNumber' => sprintf('%d-%d', $documentDate->year, $treatment->id),
            'clinic' => [
                'name' => $clinic->name,
                'address' => $this->formatClinicAddress($clinic),
                'phone' => $clinic->phone,
                'logoDataUri' => $this->logoDataUri($clinic),
            ],
            'patient' => [
                'fullName' => trim($treatment->patient->first_name.' '.$treatment->patient->last_name),
                'deleted' => $treatment->patient->trashed(),
            ],
            'doctor' => [
                'displayName' => $treatment->doctor->display_name,
                'deleted' => $treatment->doctor->trashed(),
            ],
            'documentDate' => $documentDate->locale($lang)->translatedFormat('d F Y'),
            'serviceLines' => $treatment->serviceLines->map(fn ($line) => [
                'name' => $line->service?->name ?? '—',
                'quantity' => $line->quantity,
                'unitPrice' => $this->formatMoney((string) $line->unit_price, $clinic),
                'discount' => $this->formatMoney((string) $line->discount_amount, $clinic),
                'subtotal' => $this->formatMoney((string) $line->subtotal, $clinic),
            ])->all(),
            'productLines' => $treatment->productLines->map(fn ($line) => [
                'name' => $line->product?->name ?? '—',
                'quantity' => $line->quantity,
                'unitPrice' => $this->formatMoney((string) $line->unit_price, $clinic),
                'discount' => $this->formatMoney((string) $line->discount_amount, $clinic),
                'subtotal' => $this->formatMoney((string) $line->subtotal, $clinic),
            ])->all(),
            'subtotalAmount' => $this->formatMoney((string) $treatment->subtotal_amount, $clinic),
            'discountAmount' => $this->formatMoney((string) $treatment->discount_amount, $clinic),
            'totalAmount' => $this->formatMoney((string) $treatment->total_amount, $clinic),
            'paidTotal' => $this->formatMoney($paidTotal, $clinic),
            'remainingBalance' => $this->formatMoney($remainingBalance, $clinic),
            'showPayments' => $showPayments,
            'payments' => $showPayments ? array_map(fn (array $tx) => [
                'date' => Carbon::parse($tx['paid_at'])->setTimezone($clinic->timezone)->locale($lang)->translatedFormat('d F Y'),
                'method' => __('treatment.payment.method.'.$tx['payment_method']),
                'amount' => $this->formatMoney((string) $tx['amount'], $clinic),
            ], $transactions) : [],
            'notes' => $treatment->notes,
            'details' => [
                'complaint' => $treatment->complaint,
                'diagnosis' => $treatment->diagnosis,
                'treatmentProcess' => $treatment->treatment_process,
            ],
        ];
    }

    /**
     * Locale/currency come from the TREATMENT's clinic, not ClinicContext — the document must
     * read the same whichever clinic context it is rendered from.
     */
    private function formatMoney(string $amount, Clinic $clinic): string
    {
        return Number::currency((float) $amount, $clinic->currency, $clinic->locale);
    }

    private function formatClinicAddress(Clinic $clinic): string
    {
        return implode(', ', array_filter([
            $clinic->address,
            $clinic->district,
            $clinic->city?->name,
        ]));
    }

    /**
     * Reads the logo through its Storage disk directly (never an HTTP fetch): works
     * identically for the local 'public' disk (self-signed DDEV TLS would otherwise
     * break an HTTP round-trip) and for S3, and needs no network call either way.
     * Prefers the 'thumb' conversion (a letterhead needs no more) and falls back to
     * the original until the queued conversion exists.
     */
    private function logoDataUri(Clinic $clinic): ?string
    {
        $media = $clinic->getFirstMedia('logo');

        if ($media === null) {
            return null;
        }

        $conversion = $media->hasGeneratedConversion('thumb') ? 'thumb' : '';

        $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot($conversion));

        if ($contents === null) {
            return null;
        }

        $mimeType = $conversion === 'thumb' ? 'image/webp' : $media->mime_type;

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
