<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPatientTagsRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $patient).
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
            'tag_ids' => ['array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('clinic_id', $clinicId),
            ],
        ];
    }
}
