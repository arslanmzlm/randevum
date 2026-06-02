<?php

namespace App\Modules\Medical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePatientNotesRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('updateNotes', $patient).
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
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
