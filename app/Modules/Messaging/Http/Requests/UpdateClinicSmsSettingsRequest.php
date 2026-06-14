<?php

namespace App\Modules\Messaging\Http\Requests;

use App\Enums\SmsType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClinicSmsSettingsRequest extends FormRequest
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
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['required', 'boolean'],
        ];
    }

    /**
     * Reject any key inside `settings` that is not a valid clinic-scoped SmsType.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $settings = $this->input('settings');

            if (! is_array($settings)) {
                return;
            }

            $valid = array_map(fn (SmsType $t) => $t->value, SmsType::clinicScopedCases());

            foreach (array_keys($settings) as $key) {
                if (! in_array($key, $valid, true)) {
                    $v->errors()->add("settings.{$key}", __('validation.in', ['attribute' => "settings.{$key}"]));
                }
            }
        });
    }

    /**
     * Typed accessor for the validated settings map.
     *
     * @return array<string, bool>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->validated()['settings'];

        return array_map(fn ($v) => (bool) $v, $raw);
    }
}
