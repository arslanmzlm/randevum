<?php

namespace App\Modules\Medical\Services;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
use App\Support\ClinicContext;
use Illuminate\Validation\ValidationException;

class AnamnesisService
{
    /** Morph slug used for the podiatry vertical anamnesis table. */
    private const PODIATRY_ANAMNESIS_SLUG = 'podiatry';

    /**
     * Free-text fields normalized to null when blank, so the PDF's "omit unfilled"
     * logic triggers regardless of what the client submits ('' vs null).
     *
     * @var list<string>
     */
    private const NULLABLE_TEXT_FIELDS = [
        'regular_medications',
        'other_chronic',
        'allergies',
        'foot_surgery_history',
        'current_foot_complaint',
    ];

    public function __construct(
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return ?PodiatryAnamnesis Null until the patient's first save.
     */
    public function read(Patient $patient): ?PodiatryAnamnesis
    {
        return $patient->anamnesis;
    }

    /**
     * Get-or-create the patient's single anamnesis row, then fill + save it.
     *
     * @param  array<string, mixed>  $data  Validated by UpdateAnamnesisRequest
     *
     * @throws ValidationException
     */
    public function update(Patient $patient, array $data): PodiatryAnamnesis
    {
        $this->assertVerticalMatch();

        $data = $this->normalizeBlankText($data);

        $detail = $patient->anamnesis;

        if ($detail === null) {
            $detail = PodiatryAnamnesis::create([]);
            $patient->anamnesis()->associate($detail);
            $patient->save();
        }

        $detail->fill($data)->save();

        return $detail;
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

    /**
     * Assert the active clinic's vertical slug matches the podiatry anamnesis morph slug.
     * Mirrors TreatmentService::assertVerticalMatch (cases-treatments domain rule).
     *
     * @throws ValidationException
     */
    private function assertVerticalMatch(): void
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        if ($clinic->vertical?->slug !== self::PODIATRY_ANAMNESIS_SLUG) {
            throw ValidationException::withMessages([
                'anamnesis' => [__('health.errors.vertical_mismatch')],
            ]);
        }
    }
}
