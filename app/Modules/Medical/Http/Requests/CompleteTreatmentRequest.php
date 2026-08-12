<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\ClinicContext;
use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTreatmentRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize().
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

        return [
            // Clinical detail fields
            'details' => ['nullable', 'array'],
            'details.complaint' => ['nullable', 'string', 'max:5000'],
            'details.diagnosis' => ['nullable', 'string', 'max:5000'],
            'details.treatment_process' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Treatment-level discount
            'discount_amount' => ['nullable', ...ValidationRules::money(0)],

            // Service line items — 50 is a generous abuse-guard bound (real sessions rarely
            // pass a handful), mirroring the payments/follow_up.occurrences caps below.
            'services' => ['nullable', 'array', 'max:50'],
            'services.*.service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
            'services.*.quantity' => ['required', 'integer', 'min:1'],
            'services.*.unit_price' => ['required', ...ValidationRules::money(0)],
            'services.*.discount_amount' => ['nullable', ...ValidationRules::money(0)],
            'services.*.note' => ['nullable', 'string', 'max:1000'],

            // Product line items — same abuse-guard bound as services above.
            'products' => ['nullable', 'array', 'max:50'],
            'products.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('clinic_id', $clinicId),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.unit_price' => ['required', ...ValidationRules::money(0)],
            'products.*.discount_amount' => ['nullable', ...ValidationRules::money(0)],
            'products.*.note' => ['nullable', 'string', 'max:1000'],

            // Case linking
            'case_mode' => ['required', Rule::in(['none', 'existing', 'new'])],
            'case_id' => ['required_if:case_mode,existing', 'nullable', 'integer'],
            'new_case_title' => ['required_if:case_mode,new', 'nullable', 'string', 'max:255'],

            // Optional payments — may be split across methods (e.g. part card, part cash).
            // Mutually exclusive with installment_plan (the 3rd PaymentSection mode); when
            // installment_plan is present, TreatmentService uses it instead of this loop.
            'payments' => ['nullable', 'array', 'max:4'],
            'payments.*.amount' => ['required', ...ValidationRules::money(0.01)],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],

            // Optional taksit (installment) plan — the sum-check against the computed
            // treatment total runs in the Service (the total isn't known until the line
            // items above are totalled), not here.
            'installment_plan' => ['nullable', 'array'],
            'installment_plan.installment_count' => ['required_with:installment_plan', 'integer', 'min:1', 'max:60'],
            'installment_plan.down_payment' => ['nullable', ...ValidationRules::money(0)],
            'installment_plan.down_payment_method' => [
                Rule::requiredIf(fn () => (float) ($this->input('installment_plan.down_payment') ?? 0) > 0),
                'nullable',
                Rule::enum(PaymentMethod::class),
            ],
            'installment_plan.installments' => ['required_with:installment_plan', 'array', 'min:1'],
            'installment_plan.installments.*.sequence' => ['required', 'integer', 'min:1'],
            'installment_plan.installments.*.due_date' => ['required', 'date'],
            'installment_plan.installments.*.amount' => ['required', ...ValidationRules::money(0.01)],

            // Optional follow-up booking
            'follow_up' => ['nullable', 'array'],
            'follow_up.mode' => ['required', Rule::in(['none', 'single', 'package'])],
            'follow_up.occurrences' => [
                Rule::requiredIf(fn () => ($this->input('follow_up.mode') ?? 'none') !== 'none'),
                'nullable',
                'array',
                'max:12',
                Rule::when(fn () => $this->input('follow_up.mode') === 'package', ['min:2']),
                Rule::when(fn () => $this->input('follow_up.mode') === 'single', ['size:1']),
            ],
            // Each occurrence carries its own type and duration so a package can mix
            // e.g. a 40-minute muayene with 20-minute kontrol sessions.
            'follow_up.occurrences.*' => ['required', 'array'],
            'follow_up.occurrences.*.starts_at' => ['required', 'date'],
            'follow_up.occurrences.*.duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'follow_up.occurrences.*.appointment_type_id' => [
                'nullable',
                'integer',
                Rule::exists('appointment_types', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('is_active', true),
            ],
            'follow_up.service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
        ];
    }
}
