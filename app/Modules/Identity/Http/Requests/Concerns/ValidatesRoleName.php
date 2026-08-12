<?php

namespace App\Modules\Identity\Http\Requests\Concerns;

use App\Enums\ClinicRole;
use App\Support\ClinicContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared by StoreRoleRequest (create) and RenameRoleRequest (rename): a custom role's name
 * must be unique within the clinic and must not collide with a reserved name (any ClinicRole
 * baseline case or global role name) — reusing a baseline name would recreate the resolution
 * ambiguity RoleResolver exists to remove.
 */
trait ValidatesRoleName
{
    /**
     * @return array<string, mixed>
     */
    protected function roleNameRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * $excludeRoleId lets a rename skip its own row in the "taken in clinic" check.
     */
    protected function validateRoleName(Validator $validator, ?int $excludeRoleId = null): void
    {
        if ($validator->errors()->has('name')) {
            return;
        }

        $name = Str::lower($this->string('name')->value());
        $clinicId = app(ClinicContext::class)->id();

        $takenInClinic = DB::table('roles')
            ->where('clinic_id', $clinicId)
            ->where('guard_name', 'web')
            ->whereRaw('lower(name) = ?', [$name])
            ->when($excludeRoleId !== null, fn ($query) => $query->where('id', '!=', $excludeRoleId))
            ->exists();

        if ($takenInClinic) {
            $validator->errors()->add('name', __('validation.role_name_taken'));

            return;
        }

        // ClinicRole first: a missing/partially seeded global row must not let a clinic
        // claim a baseline name, which RoleResolver would then prefer over the template.
        $reserved = ClinicRole::tryFrom($name) !== null
            || DB::table('roles')
                ->whereNull('clinic_id')
                ->where('guard_name', 'web')
                ->whereRaw('lower(name) = ?', [$name])
                ->exists();

        if ($reserved) {
            $validator->errors()->add('name', __('validation.role_name_reserved'));
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.role_name')];
    }
}
