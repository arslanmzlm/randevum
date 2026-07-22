<?php

namespace App\Modules\Billing\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\ClinicContext;
use App\Support\ValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentPlanRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', PaymentPlan::class).
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
        $clinicId = app(ClinicContext::class)->id();
        $patientId = (int) $this->input('patient_id');

        return [
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinicId),
            ],
            'treatment_id' => [
                'nullable',
                'integer',
                Rule::exists('treatments', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('patient_id', $patientId),
            ],
            'total_amount' => ['required', ...ValidationRules::money(0.01)],
            'down_payment' => ['nullable', ...ValidationRules::money(0)],
            'down_payment_method' => [
                Rule::requiredIf(fn () => (float) ($this->input('down_payment') ?? 0) > 0),
                'nullable',
                Rule::enum(PaymentMethod::class),
            ],
            'installment_count' => ['required', 'integer', 'min:1', 'max:60'],
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.sequence' => ['required', 'integer', 'min:1'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', ...ValidationRules::money(0.01)],
        ];
    }

    /**
     * Server-side sum-check: Σ installment amounts + down_payment must equal total_amount
     * (tolerance 0.00), and sequences must be a contiguous 1..N. Re-guarded in
     * PaymentPlanService::create.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $installments = $this->input('installments', []);

                if (! is_array($installments) || $installments === []) {
                    return;
                }

                $sum = '0.00';
                $sequences = [];

                foreach ($installments as $row) {
                    $sum = bcadd($sum, (string) ($row['amount'] ?? 0), 2);
                    $sequences[] = (int) ($row['sequence'] ?? 0);
                }

                $downPayment = (string) ($this->input('down_payment') ?? 0);
                $total = (string) $this->input('total_amount');

                if (bccomp(bcadd($sum, $downPayment, 2), $total, 2) !== 0) {
                    $validator->errors()->add('installments', __('payment_plan.errors.sum_mismatch'));
                }

                sort($sequences);
                $expected = range(1, count($installments));

                if ($sequences !== $expected) {
                    $validator->errors()->add('installments', __('payment_plan.errors.sequence_invalid'));
                }
            },
        ];
    }
}
