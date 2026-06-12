<?php

namespace App\Modules\Medical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkCaseTreatmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled in the controller via authorize().
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'treatment_ids' => ['required', 'array', 'min:1'],
            'treatment_ids.*' => ['integer'],
        ];
    }
}
