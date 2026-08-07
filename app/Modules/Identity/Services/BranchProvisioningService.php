<?php

namespace App\Modules\Identity\Services;

use App\Enums\ClinicRole;
use App\Models\Clinic;
use App\Models\User;
use App\Modules\Catalog\Contracts\CatalogCloneContract;
use App\Modules\Core\Services\RoleResolver;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Identity\Support\ClinicSlug;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Opens a new branch (clinic) under an existing tenant, assigns the creator as its
 * owner, and optionally clones the source clinic's active catalog. Mirrors the shape
 * of ClinicRegistrationService (tenant→clinic→role), scoped to "one more clinic under
 * an already-existing tenant" instead of a brand-new tenant + user.
 */
class BranchProvisioningService
{
    public function __construct(
        private RoleResolver $roleResolver,
        private CatalogCloneContract $catalogClone,
    ) {}

    /**
     * @param  array{name: string, vertical_id: int, copy_catalog: bool}  $data
     */
    public function create(User $creator, Clinic $sourceClinic, array $data): Clinic
    {
        $branch = DB::transaction(function () use ($creator, $sourceClinic, $data): Clinic {
            $branch = Clinic::create([
                'tenant_id' => $sourceClinic->tenant_id,
                'vertical_id' => $data['vertical_id'],
                'name' => $data['name'],
                'slug' => ClinicSlug::unique($data['name']),
                'country_id' => $sourceClinic->country_id,
                'timezone' => $sourceClinic->timezone,
                'locale' => $sourceClinic->locale,
                'currency' => $sourceClinic->currency,
                'working_hours' => Clinic::defaultWorkingHours(),
                'onboarded_at' => now(),
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($branch->id);
            $creator->assignRole($this->roleResolver->resolveForClinic($branch->id, ClinicRole::Owner->value));

            // Restore the team context to the source clinic — the creator keeps
            // working in the branch they came from until they explicitly switch.
            app(PermissionRegistrar::class)->setPermissionsTeamId($sourceClinic->id);
            $creator->unsetRelation('roles');
            $creator->unsetRelation('permissions');

            if ($data['copy_catalog']) {
                $this->catalogClone->cloneCatalog($sourceClinic->id, $branch->id);
            }

            return $branch;
        });

        // Dispatched after commit so listeners (appointment/follow-up type
        // provisioning) see a persisted branch, exactly as at signup. The branch is
        // already committed, so a listener failure must not surface as a request
        // error — retried in-process (listeners provision via firstOrCreate, safe to
        // re-run) and, if still failing, logged for manual follow-up instead.
        try {
            retry(2, fn () => event(new ClinicRegistered($branch)), 200);
        } catch (\Throwable $e) {
            report($e);
        }

        return $branch;
    }
}
