<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateTagRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $tag).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalize color: ensure leading '#' and uppercase hex digits.
        if ($this->filled('color')) {
            $color = ltrim((string) $this->input('color'), '#');
            $this->merge(['color' => '#'.strtoupper($color)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $clinicId = app(ClinicContext::class)->id();
                $tag = $this->route('tag');

                // Case-insensitive per-clinic uniqueness, ignoring self.
                $taken = DB::table('tags')
                    ->where('clinic_id', $clinicId)
                    ->where('id', '!=', $tag->id)
                    ->whereRaw('lower(name) = ?', [Str::lower($this->string('name')->value())])
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', __('validation.tag_name_taken'));
                }
            },
        ];
    }
}
