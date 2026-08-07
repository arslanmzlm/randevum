<?php

namespace App\Modules\Medical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteFollowUpRequest extends FormRequest
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
            'result_note' => ['nullable', 'string'],
        ];
    }
}
