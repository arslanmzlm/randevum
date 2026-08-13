<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Enums\StockAdjustmentMode;
use App\Enums\StockMovementReason;
use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual stock adjustment, in either of two shapes:
 *
 * - movement: a reason plus a positive `quantity`; the reason's direction gives the sign.
 * - count: the new `current_stock` total; the delta is whatever closes the gap.
 *
 * One endpoint, one request — `mode` is the discriminator, so the ledger keeps a single
 * write path and the two shapes cannot drift apart.
 */
class UpdateProductStockRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('manageStock', $product).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(StockAdjustmentMode::class)],

            // Movement mode: always a positive amount — the direction is the reason's job.
            'quantity' => ['required_if:mode,'.StockAdjustmentMode::Movement->value, 'integer', 'min:1'],

            // Count mode: the new total. No min:0 — stock may go negative (project rule).
            'current_stock' => ['required_if:mode,'.StockAdjustmentMode::Count->value, 'integer'],

            // Required in movement mode (nothing else supplies the sign); optional in count
            // mode, where the controller falls back to CountCorrection.
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->stockMode() === StockAdjustmentMode::Movement),
                'nullable',
                Rule::enum(StockMovementReason::class)->only(
                    $this->stockMode() === StockAdjustmentMode::Movement
                        ? StockMovementReason::movementModeCases()
                        : StockMovementReason::manualCases()
                ),
            ],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->stockMode() !== StockAdjustmentMode::Count) {
                return;
            }

            $reason = $this->stockReason();
            $sign = $reason->sign();

            if ($sign === null) {
                return;
            }

            $product = $this->route('product');

            if (! $product instanceof Product) {
                return;
            }

            $delta = $this->integer('current_stock') - $product->current_stock;

            // A fixed-direction reason and a new total that moves the other way (or doesn't
            // move at all) describe two different things — refuse rather than silently
            // writing a "stock in" row that lowers stock.
            if (($delta <=> 0) === $sign) {
                return;
            }

            $validator->errors()->add('current_stock', __(
                $sign === 1
                    ? 'validation.custom.stock_adjustment.count_expects_increase'
                    : 'validation.custom.stock_adjustment.count_expects_decrease',
                [
                    'reason' => __('stock_movement.reason.'.$reason->value),
                    'current' => $product->current_stock,
                ],
            ));
        });
    }

    public function stockMode(): ?StockAdjustmentMode
    {
        return StockAdjustmentMode::tryFrom((string) $this->input('mode'));
    }

    /**
     * The chosen reason. Count mode may leave it out and gets the count correction; movement
     * mode cannot (`required` above), so the fallback is unreachable there.
     */
    public function stockReason(): StockMovementReason
    {
        return StockMovementReason::tryFrom((string) $this->input('reason'))
            ?? StockMovementReason::CountCorrection;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => __('validation.attributes.stock_quantity'),
        ];
    }
}
