<?php

namespace App\Modules\Identity\Services;

use App\Enums\ClinicRole;
use App\Models\Clinic;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Modules\Core\Services\RoleResolver;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Identity\Support\ClinicSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the complete starting account for a self-service clinic owner:
 * tenant → clinic → user → owner role → signup consents, all in a single transaction.
 */
class ClinicRegistrationService
{
    public function __construct(
        private ConsentRecorderContract $consentRecorder,
        private RoleResolver $roleResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, ?string $ipAddress = null, ?string $userAgent = null): User
    {
        /** @var array{user: User, clinic: Clinic} $result */
        $result = DB::transaction(function () use ($data, $ipAddress, $userAgent): array {
            $tenant = Tenant::create(['name' => $data['clinic_name']]);

            $countryId = Country::where('code', 'TR')->value('id');

            $clinic = Clinic::create([
                'tenant_id' => $tenant->id,
                'vertical_id' => (int) $data['vertical_id'],
                'name' => $data['clinic_name'],
                'slug' => ClinicSlug::unique($data['clinic_name']),
                'country_id' => $countryId,
                'working_hours' => Clinic::defaultWorkingHours(),
                'onboarded_at' => now(),
            ]);

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
            $user->assignRole($this->roleResolver->resolveForClinic($clinic->id, ClinicRole::Owner->value));

            // Record the owner's acceptance of the platform legal documents (Terms, Privacy,
            // DPA) atomically — the signup rolls back if a required document is missing.
            $this->consentRecorder->recordRegistrationConsents($user, $ipAddress, $userAgent);

            return ['user' => $user, 'clinic' => $clinic];
        });

        // Dispatch after the transaction commits so listeners see persisted rows. The
        // tenant+clinic+user are already committed at this point, so a provisioning
        // listener failure must not surface as a registration error — it's retried
        // in-process (listeners provision via firstOrCreate, safe to re-run) and, if
        // still failing, logged for manual follow-up rather than failing the signup.
        try {
            retry(2, fn () => event(new ClinicRegistered($result['clinic'])), 200);
        } catch (\Throwable $e) {
            report($e);
        }

        return $result['user'];
    }
}
