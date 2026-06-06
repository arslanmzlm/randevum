<?php

namespace App\Modules\Identity\Services;

use App\Models\Clinic;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Identity\Events\ClinicRegistered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the complete starting account for a self-service clinic owner:
 * tenant → clinic → user → owner role, all in a single transaction.
 */
class ClinicRegistrationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): User
    {
        /** @var array{user: User, clinic: Clinic} $result */
        $result = DB::transaction(function () use ($data): array {
            $tenant = Tenant::create(['name' => $data['clinic_name']]);

            $countryId = Country::where('code', 'TR')->value('id');

            $clinic = Clinic::create([
                'tenant_id' => $tenant->id,
                'vertical_id' => (int) $data['vertical_id'],
                'name' => $data['clinic_name'],
                'slug' => $this->uniqueSlug($data['clinic_name']),
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
            $user->assignRole('owner');

            return ['user' => $user, 'clinic' => $clinic];
        });

        // Dispatch after the transaction commits so listeners see persisted rows.
        event(new ClinicRegistered($result['clinic']));

        return $result['user'];
    }

    private function uniqueSlug(string $clinicName): string
    {
        $base = Str::slug($clinicName);
        $slug = $base;
        $suffix = 2;

        while (Clinic::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
