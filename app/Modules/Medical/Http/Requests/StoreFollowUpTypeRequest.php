<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreFollowUpTypeRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', FollowUpType::class).
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
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
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

                // Case-insensitive per-clinic uniqueness among non-deleted rows — mirrors the
                // DB-level functional index (a soft-deleted name must be reusable).
                $taken = DB::table('follow_up_types')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at')
                    ->whereRaw('lower(name) = ?', [Str::lower($this->string('name')->value())])
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', __('validation.follow_up_type_name_taken'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.follow_up_type_name')];
    }
}
