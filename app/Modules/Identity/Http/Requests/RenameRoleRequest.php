<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Http\Requests\Concerns\ValidatesRoleName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RenameRoleRequest extends FormRequest
{
    use ValidatesRoleName;

    /**
     * Authorization is handled by the controller via $this->authorize('rename', $role) — which
     * role is confirmed there (custom, this clinic's), so this request only validates the name.
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
        return $this->roleNameRules();
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            // Exclude the role's own row, or renaming it back to its current name (or just
            // changing case) would trip the "taken in clinic" check against itself.
            fn (Validator $validator) => $this->validateRoleName($validator, $this->route('role')->id),
        ];
    }
}
