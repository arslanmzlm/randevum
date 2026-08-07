<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.viewAny');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete');
    }

    public function manageStock(User $user, Product $product): bool
    {
        return $user->can('products.manageStock');
    }

    public function viewMovements(User $user, Product $product): bool
    {
        return $user->can('products.manageStock') || $user->can('products.viewAny');
    }
}
