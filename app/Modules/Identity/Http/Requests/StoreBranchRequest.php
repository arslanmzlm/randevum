<?php

namespace App\Modules\Identity\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Clinic::class).
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
            'name' => ['required', 'string', 'max:255'],
            'vertical_id' => ['required', 'integer', Rule::exists('verticals', 'id')->where('is_active', true)],
            'copy_catalog' => ['boolean'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('copy_catalog') || $validator->errors()->has('vertical_id')) {
                    return;
                }

                $activeClinic = app(ClinicContext::class)->clinicOrFail();

                // A copy across verticals would import catalog rows carrying the
                // wrong vertical_id.
                if ((int) $this->integer('vertical_id') !== $activeClinic->vertical_id) {
                    $validator->errors()->add(
                        'copy_catalog',
                        __('validation.custom.copy_catalog.vertical_mismatch'),
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('validation.attributes.name'),
            'vertical_id' => __('validation.attributes.vertical_id'),
            'copy_catalog' => __('validation.attributes.copy_catalog'),
        ];
    }
}
