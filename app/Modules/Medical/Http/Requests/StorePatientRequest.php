<?php

namespace App\Modules\Medical\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTrPhone;
use App\Modules\Medical\Http\Requests\Concerns\PatientFieldRules;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    use NormalizesTrPhone;
    use PatientFieldRules;

    /**
     * Authorization is handled by the controller via $this->authorize('create', Patient::class).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhone('phone');
        $this->normalizePhone('contact_phone');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();

        return $this->patientRules(
            Rule::unique('patients', 'phone')
                ->where('clinic_id', $clinicId)
                ->whereNull('deleted_at')
        );
    }
}
