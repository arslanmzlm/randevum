<?php

namespace App\Modules\Medical\Services;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
use App\Support\ClinicContext;
use Illuminate\Validation\ValidationException;

class AnamnesisService
{
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
     * Assert the active clinic's vertical slug matches the podiatry anamnesis morph slug.
     * Mirrors TreatmentService::assertVerticalMatch (cases-treatments domain rule).
     *
     * @throws ValidationException
     */
    private function assertVerticalMatch(): void
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        if ($clinic->vertical?->slug !== 'podiatry') {
            throw ValidationException::withMessages([
                'anamnesis' => [__('health.errors.vertical_mismatch')],
            ]);
        }
    }
}
