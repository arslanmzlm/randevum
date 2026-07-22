<?php

namespace App\Modules\Messaging\Http\Requests;

use App\Enums\SmsType;
use App\Modules\Messaging\Support\SmsSegmentCalculator;
use App\Modules\Messaging\Support\SmsTemplateVariables;
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
            'templates' => ['nullable', 'array'],
            // Hard upper bound as a cheap guard before the segment-count check below;
            // the real business cap is the encoding-aware max_segments check.
            'templates.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Reject any key inside `settings` that is not a valid clinic-scoped SmsType;
     * any key inside `templates` that is not a customizable SmsType; any template
     * containing a non-allowlisted `:token`; and any template over the configured
     * max segment count.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateSettingsKeys($v);
            $this->validateTemplates($v);
        });
    }

    private function validateSettingsKeys(Validator $v): void
    {
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
    }

    private function validateTemplates(Validator $v): void
    {
        $templates = $this->input('templates');

        if (! is_array($templates)) {
            return;
        }

        $validKeys = array_map(fn (SmsType $t) => $t->value, SmsType::customizableCases());
        $calculator = new SmsSegmentCalculator;
        $maxSegments = (int) config('platform.sms.max_segments');

        foreach ($templates as $key => $value) {
            $field = "templates.{$key}";

            if (! in_array($key, $validKeys, true)) {
                $v->errors()->add($field, __('validation.sms_template_unknown_type', ['attribute' => $field]));

                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            foreach ($this->tokensIn($value) as $token) {
                if (! in_array($token, SmsTemplateVariables::ALLOWED, true)) {
                    $v->errors()->add($field, __('validation.sms_template_unknown_variable', [
                        'attribute' => $field,
                        'variable' => ":{$token}",
                    ]));
                }
            }

            $count = $calculator->count($value);

            if ($count['segments'] > $maxSegments) {
                $v->errors()->add($field, __('validation.sms_template_segment_limit', [
                    'attribute' => $field,
                    'max' => $maxSegments,
                    'segments' => $count['segments'],
                ]));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tokensIn(string $template): array
    {
        preg_match_all('/:([a-z_]+)/', $template, $matches);

        return array_values(array_unique($matches[1]));
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

    /**
     * Typed accessor for the validated templates map. An empty or
     * whitespace-only string resets the type to its lang default (stored as null).
     *
     * @return array<string, ?string>
     */
    public function templates(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->validated()['templates'] ?? [];

        return array_map(fn ($v) => $v === null || trim((string) $v) === '' ? null : (string) $v, $raw);
    }
}
