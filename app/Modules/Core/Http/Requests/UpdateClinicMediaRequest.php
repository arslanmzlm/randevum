<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClinicMediaRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $clinic).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Per-collection dimension floors so Fit::Crop never has to upscale.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $collection = $this->route('collection');

        $dimensionRule = match ($collection) {
            'cover' => 'dimensions:min_width=1920,min_height=1080',
            'cover_mobile' => 'dimensions:min_width=1440,min_height=1440',
            default => 'dimensions:min_width=128,min_height=128', // logo, logo_dark, logo_icon
        };

        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', $dimensionRule],
        ];
    }
}
