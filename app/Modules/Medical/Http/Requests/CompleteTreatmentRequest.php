<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\PaymentMethod;
use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            $payments = $this->input('payments') ?? [];

            if (is_array($payments) && $payments !== [] && ! $user->can('transactions.create')) {
                $validator->errors()->add('payments', __('treatment.errors.payment_not_allowed'));
            }

            if ($this->input('case_mode') === 'new' && ! $user->can('cases.create')) {
                $validator->errors()->add('case_mode', __('treatment.errors.case_create_not_allowed'));
            }

            if (($this->input('follow_up.mode') ?? 'none') !== 'none' && ! $user->can('appointments.create')) {
                $validator->errors()->add('follow_up.mode', __('treatment.errors.follow_up_not_allowed'));
            }
        });
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
            'discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Service line items
            'services' => ['nullable', 'array'],
            'services.*.service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
            'services.*.quantity' => ['required', 'integer', 'min:1'],
            'services.*.unit_price' => ['required', 'numeric', 'min:0'],
            'services.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.note' => ['nullable', 'string', 'max:1000'],

            // Product line items
            'products' => ['nullable', 'array'],
            'products.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('clinic_id', $clinicId),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.unit_price' => ['required', 'numeric', 'min:0'],
            'products.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'products.*.note' => ['nullable', 'string', 'max:1000'],

            // Case linking
            'case_mode' => ['required', Rule::in(['none', 'existing', 'new'])],
            'case_id' => ['required_if:case_mode,existing', 'nullable', 'integer'],
            'new_case_title' => ['required_if:case_mode,new', 'nullable', 'string', 'max:255'],

            // Optional payments — may be split across methods (e.g. part card, part cash)
            'payments' => ['nullable', 'array', 'max:4'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],

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
