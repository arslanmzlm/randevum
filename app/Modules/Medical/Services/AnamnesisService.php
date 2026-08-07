<?php

namespace App\Modules\Medical\Services;

use App\Models\Anamnesis;
use App\Models\Patient;

class AnamnesisService
{
    /**
     * Free-text fields normalized to null when blank, so the PDF's "omit unfilled"
     * logic triggers regardless of what the client submits ('' vs null).
     *
     * @var list<string>
     */
    private const NULLABLE_TEXT_FIELDS = [
        'infectious_disease_note',
        'regular_medications',
        'other_chronic',
        'allergies',
        'surgery_history',
        'family_history',
        'menstrual_notes',
        'physician_name',
        'physician_phone',
    ];

    public function __construct(
        private AnamnesisFieldService $anamnesisFieldService,
    ) {}

    /**
     * @return ?Anamnesis Null until the patient's first save.
     */
    public function read(Patient $patient): ?Anamnesis
    {
        return $patient->anamnesis;
    }

    /**
     * Get-or-create the patient's single anamnesis row, then fill + save it.
     *
     * @param  array<string, mixed>  $data  Validated by UpdateAnamnesisRequest
     */
    public function update(Patient $patient, array $data): Anamnesis
    {
        $anamnesis = Anamnesis::firstOrNew(['patient_id' => $patient->id]);

        $extra = $data['extra'] ?? [];
        unset($data['extra']);

        $data = $this->normalizeBlankText($data);

        $activeFields = $this->anamnesisFieldService->definitionsForActiveClinic();
        $filteredExtra = $this->anamnesisFieldService->filterExtra($extra, $activeFields);

        // Merge over the stored bag so a partial submit does not wipe untouched keys, then drop
        // any key filterExtra() explicitly nulled out — a submitted-but-blank field clears the
        // previously stored answer instead of being re-filled by the merge.
        $merged = array_merge($anamnesis->extra ?? [], $filteredExtra);
        $data['extra'] = array_filter($merged, fn ($value): bool => $value !== null);

        $anamnesis->fill($data)->save();

        return $anamnesis;
    }

    /**
     * Trim free-text fields and collapse blank strings to null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeBlankText(array $data): array
    {
        foreach (self::NULLABLE_TEXT_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        return $data;
    }
}
