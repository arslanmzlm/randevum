<?php

namespace App\Modules\Identity\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreRoleRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('create', Role::class).
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
            'name' => ['required', 'string', 'max:50'],
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

                $name = Str::lower($this->string('name')->value());
                $clinicId = app(ClinicContext::class)->id();

                $takenInClinic = DB::table('roles')
                    ->where('clinic_id', $clinicId)
                    ->where('guard_name', 'web')
                    ->whereRaw('lower(name) = ?', [$name])
                    ->exists();

                if ($takenInClinic) {
                    $validator->errors()->add('name', __('validation.role_name_taken'));

                    return;
                }

                // A clinic role sharing a global name would re-create the resolution
                // ambiguity RoleResolver exists to remove.
                $reserved = DB::table('roles')
                    ->whereNull('clinic_id')
                    ->where('guard_name', 'web')
                    ->whereRaw('lower(name) = ?', [$name])
                    ->exists();

                if ($reserved) {
                    $validator->errors()->add('name', __('validation.role_name_reserved'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.role_name')];
    }
}
