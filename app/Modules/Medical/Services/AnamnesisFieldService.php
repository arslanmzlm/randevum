<?php

namespace App\Modules\Medical\Services;

use App\Enums\AnamnesisFieldType;
use App\Models\AnamnesisField;
use App\Modules\Medical\Repositories\AnamnesisFieldRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

class AnamnesisFieldService
{
    public function __construct(
        private AnamnesisFieldRepository $anamnesisFieldRepository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Definitions for the active clinic's vertical. Active-only for the form/validation
     * (default); the PDF/summary card ask for the full set (inactive included) so a
     * retired field's stored value keeps rendering.
     *
     * @return Collection<int, AnamnesisField>
     */
    public function definitionsForActiveClinic(bool $includeInactive = false): Collection
    {
        $clinic = $this->clinicContext->clinicOrFail();

        return $includeInactive
            ? $this->anamnesisFieldRepository->allForVertical($clinic->vertical_id, $clinic->id)
            : $this->anamnesisFieldRepository->activeForVertical($clinic->vertical_id, $clinic->id);
    }

    /**
     * Inertia prop shape (AnamnesisFieldDefinition[]) — label/group/option labels resolved
     * through __() server-side so the frontend never translates a dynamic definition.
     *
     * @param  Collection<int, AnamnesisField>  $fields
     * @return list<array<string, mixed>>
     */
    public function toPropArray(Collection $fields): array
    {
        return $fields->map(fn (AnamnesisField $field): array => [
            'key' => $field->key,
            'label' => __($field->label),
            'group' => __($field->group),
            'type' => $field->type->value,
            'options' => $this->translatedOptions($field),
            'required' => $field->required,
            'sort' => $field->sort,
        ])->values()->all();
    }

    /**
     * Validation rules for the `extra` payload, keyed `extra.<field key>`.
     *
     * @param  Collection<int, AnamnesisField>  $fields
     * @return array<string, mixed>
     */
    public function validationRules(Collection $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $key = 'extra.'.$field->key;
            $isMultiselect = $field->type === AnamnesisFieldType::Multiselect;

            $presence = match (true) {
                $field->required && $isMultiselect => ['required', 'min:1'],
                $field->required => ['required'],
                default => ['nullable'],
            };

            $rules[$key] = [...$presence, ...match ($field->type) {
                AnamnesisFieldType::Text => ['string', 'max:255'],
                AnamnesisFieldType::Textarea => ['string', 'max:2000'],
                AnamnesisFieldType::Boolean => ['boolean'],
                AnamnesisFieldType::Number => ['numeric'],
                AnamnesisFieldType::Date => ['date'],
                AnamnesisFieldType::Select => ['string', Rule::in($this->optionValues($field))],
                AnamnesisFieldType::Multiselect => ['array'],
            }];

            if ($isMultiselect) {
                $rules[$key.'.*'] = ['string', Rule::in($this->optionValues($field))];
            }
        }

        return $rules;
    }

    /**
     * Keeps only keys with an active definition and casts booleans. A key with no active
     * definition (retired between page load and submit) is silently dropped — it is simply
     * never iterated. A key that WAS submitted but normalizes to blank (empty string/array,
     * non-numeric) is kept with an explicit `null` value, so the caller can tell "not
     * submitted" (absent) apart from "submitted blank" (null) and clear a stored answer
     * instead of leaving it untouched.
     *
     * @param  array<string, mixed>  $submitted
     * @param  Collection<int, AnamnesisField>  $activeFields
     * @return array<string, mixed>
     */
    public function filterExtra(array $submitted, Collection $activeFields): array
    {
        $result = [];

        foreach ($activeFields as $field) {
            if (! array_key_exists($field->key, $submitted)) {
                continue;
            }

            $value = $submitted[$field->key];

            $result[$field->key] = match ($field->type) {
                AnamnesisFieldType::Boolean => (bool) $value,
                AnamnesisFieldType::Text, AnamnesisFieldType::Textarea, AnamnesisFieldType::Select, AnamnesisFieldType::Date => $this->normalizeBlankString($value),
                AnamnesisFieldType::Multiselect => (is_array($value) && $value !== []) ? array_values($value) : null,
                AnamnesisFieldType::Number => is_numeric($value) ? $value + 0 : null,
            };
        }

        return $result;
    }

    private function normalizeBlankString(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function optionValues(AnamnesisField $field): array
    {
        return collect($field->options ?? [])->pluck('value')->all();
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private function translatedOptions(AnamnesisField $field): ?array
    {
        if ($field->options === null) {
            return null;
        }

        return collect($field->options)
            ->map(fn (array $option): array => [
                'value' => $option['value'],
                'label' => __($option['label']),
            ])
            ->values()
            ->all();
    }
}
