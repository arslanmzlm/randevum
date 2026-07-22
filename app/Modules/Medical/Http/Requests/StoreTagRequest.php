<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreTagRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Tag::class).
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

                // Case-insensitive per-clinic uniqueness (mirrors the DB-level functional
                // index) — prevents "VIP" / "vip" drift, the whole point of a curated list.
                $taken = DB::table('tags')
                    ->where('clinic_id', $clinicId)
                    ->whereRaw('lower(name) = ?', [Str::lower($this->string('name')->value())])
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', __('validation.tag_name_taken'));
                }
            },
        ];
    }
}
