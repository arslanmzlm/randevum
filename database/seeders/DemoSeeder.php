<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Clinic;
use App\Models\Country;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DemoSeeder extends Seeder
{
    /**
     * Local/dev demo data: 1 platform superadmin + 1 tenant with 1 clinic and its owner.
     * Requires RoleSeeder, CountrySeeder, CitySeeder and VerticalSeeder to have run first.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // platform superadmin (global role)
        $superadmin = User::firstOrCreate(
            ['email' => 'superadmin@randevum.test'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $registrar->setPermissionsTeamId(null);
        $superadmin->assignRole('superadmin');

        // demo clinic
        $turkey = Country::where('code', 'TR')->firstOrFail();
        $izmir = City::where('country_id', $turkey->id)->where('code', '35')->firstOrFail();
        $podiatry = Vertical::where('slug', 'podiatry')->firstOrFail();

        $tenant = Tenant::firstOrCreate(['name' => 'Podosen']);

        $clinic = Clinic::firstOrCreate(
            ['slug' => 'podosen-izmir'],
            [
                'tenant_id' => $tenant->id,
                'vertical_id' => $podiatry->id,
                'name' => 'Podosen Diyabetik Ayak Kliniği',
                'phone' => '0232 463 12 34',
                'country_id' => $turkey->id,
                'city_id' => $izmir->id,
                'address' => 'Alsancak, İzmir',
                'working_hours' => Clinic::defaultWorkingHours(),
                'onboarded_at' => now(),
            ],
        );

        // clinic owner (clinic-scoped role)
        $owner = User::firstOrCreate(
            ['email' => 'owner@podosen.test'],
            [
                'first_name' => 'Klinik',
                'last_name' => 'Sahibi',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $registrar->setPermissionsTeamId($clinic->id);
        $owner->assignRole('owner');

        // demo doctors: user + clinic-scoped `doctor` role + doctor profile (calendar visibility
        // comes from the profile row, not the role). Mirrors DoctorProfileService::addDoctor.
        $doctors = [
            ['first_name' => 'Mehmet', 'last_name' => 'Yılmaz', 'email' => 'doctor@podosen.test', 'title' => 'Dr.', 'specialization' => 'Podoloji', 'license_number' => 'TR-10234'],
            ['first_name' => 'Ayla', 'last_name' => 'Demir', 'email' => 'ayla@podosen.test', 'title' => 'Uzm. Dr.', 'specialization' => 'Diyabetik Ayak Bakımı', 'license_number' => 'TR-20456'],
            ['first_name' => 'Canan', 'last_name' => 'Kaya', 'email' => 'canan@podosen.test', 'title' => 'Dr.', 'specialization' => 'Ortopedi', 'license_number' => 'TR-30678'],
        ];

        foreach ($doctors as $data) {
            $doctorUser = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
            $doctorUser->assignRole('doctor');

            Doctor::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $doctorUser->id],
                [
                    'clinic_id' => $clinic->id,
                    'title' => $data['title'],
                    'specialization' => $data['specialization'],
                    'license_number' => $data['license_number'],
                    'is_active' => true,
                ],
            );
        }

        // Demo patients for the clinic — top up to 100 so list/search screens have
        // realistic data. Idempotent: only creates the shortfall on re-seed.
        $existing = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

        if ($existing < 100) {
            Patient::factory()->count(100 - $existing)->create(['clinic_id' => $clinic->id]);
        }

        // The first-login password reminder fires while last_login_at is null and would greet every
        // reviewer on a freshly seeded database. Not fillable, so stamp it in one pass.
        User::whereNull('last_login_at')->update(['last_login_at' => now()]);

        // Appointments are seeded by DemoAppointmentsSeeder (runs last) so they can reference the
        // vertical's services, which are seeded after this seeder.
    }
}
