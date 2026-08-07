<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateFollowUpTypeRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', $followUpType).
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
                $followUpType = $this->route('followUpType');

                // Case-insensitive per-clinic uniqueness among non-deleted rows, ignoring self.
                $taken = DB::table('follow_up_types')
                    ->where('clinic_id', $clinicId)
                    ->where('id', '!=', $followUpType->id)
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
