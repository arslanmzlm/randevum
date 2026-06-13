<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full treatment shape for the Show page.
 *
 * @mixin Treatment
 */
class TreatmentShowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Derive paid total from transactions — balance is never stored.
        $paidTotal = $this->transactions->sum('amount');

        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'appointment' => [
                'id' => $this->appointment->id,
                'starts_at' => $this->appointment->starts_at->toIso8601String(),
            ],
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
            ],
            'doctor' => [
                'display_name' => $this->doctor->display_name,
            ],
            'case' => $this->case ? [
                'id' => $this->case->id,
                'title' => $this->case->title,
            ] : null,
            'details' => [
                'complaint' => $this->details?->complaint,
                'diagnosis' => $this->details?->diagnosis,
                'treatment_process' => $this->details?->treatment_process,
            ],
            'serviceLines' => $this->serviceLines->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->service?->name ?? '—',
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'subtotal' => $line->subtotal,
                'note' => $line->note,
            ])->all(),
            'productLines' => $this->productLines->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->product?->name ?? '—',
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'subtotal' => $line->subtotal,
                'note' => $line->note,
            ])->all(),
            'notes' => $this->notes,
            'subtotal_amount' => $this->subtotal_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'paid_total' => (string) $paidTotal,
        ];

        if ($request->user()?->can('transactions.viewAny')) {
            $data['transactions'] = $this->transactions
                ->sortByDesc(fn ($tx) => [$tx->paid_at->timestamp, $tx->id])
                ->values()
                ->map(fn ($tx) => [
                    'id' => $tx->id,
                    'paid_at' => $tx->paid_at->toIso8601String(),
                    'payment_method' => $tx->payment_method->value,
                    'amount' => (string) $tx->amount,
                    'note' => $tx->note,
                    'status' => $tx->status->value,
                    'treatment_id' => $tx->treatment_id,
                ])->all();
        }

        return $data;
    }
}
