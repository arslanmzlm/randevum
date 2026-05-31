<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDoctorAvatarRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $doctor).
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
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=256,min_height=256',
            ],
        ];
    }
}
