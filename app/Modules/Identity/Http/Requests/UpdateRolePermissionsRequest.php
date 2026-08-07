<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Repositories\RoleRepository;
use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('update', Role::class).
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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.id' => ['required', 'integer', 'distinct', Rule::in($this->editableRoleIds())],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * The role ids this clinic may submit — the tenant-isolation gate for the bulk save.
     *
     * @return list<int>
     */
    private function editableRoleIds(): array
    {
        return app(RoleRepository::class)
            ->columnsForClinic(app(ClinicContext::class)->id())
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['roles' => __('validation.attributes.role_permissions')];
    }
}
