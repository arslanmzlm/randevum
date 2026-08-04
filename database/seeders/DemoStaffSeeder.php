<?php

namespace Database\Seeders;

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * The non-doctor half of the demo clinic's staff (manager / receptionist / assistant) plus the
 * clinic's SMS preferences, so every permission-gated control can be reviewed by logging in as
 * the role that owns it, and the SMS settings screen has both default and customised templates.
 *
 * Runs AFTER DemoSeeder (needs the clinic + its roles).
 */
class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();

        if (! $clinic) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($clinic->id);

        $staff = [
            ['manager', 'Merve', 'Aydın', 'manager@podosen.test'],
            ['receptionist', 'Selin', 'Koç', 'reception@podosen.test'],
            ['assistant', 'Burak', 'Şahin', 'assistant@podosen.test'],
        ];

        foreach ($staff as [$role, $firstName, $lastName, $email]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $user->assignRole($role);

            // The first-login password reminder fires while last_login_at is null and would greet
            // every reviewer on a freshly seeded database. Not fillable, so stamp it directly.
            if ($user->last_login_at === null) {
                $user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        }

        // One row per clinic-scoped type: two switched off and two customised, so the settings
        // screen shows every state instead of a uniform wall of enabled defaults.
        $customTemplates = [
            SmsType::Reminder24h->value => 'Sn. :patient, :date :time randevunuzu hatırlatırız. :clinic',
            SmsType::AppointmentCreated->value => ':clinic randevunuz oluşturuldu: :date :time, :doctor.',
        ];

        $disabled = [SmsType::Reminder1h->value, SmsType::BalanceReminder->value];

        foreach (SmsType::cases() as $type) {
            if ($type === SmsType::Otp) {
                continue; // platform-level, never a clinic preference
            }

            ClinicSmsSetting::withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinic->id, 'sms_type' => $type->value],
                [
                    'enabled' => ! in_array($type->value, $disabled, true),
                    'template' => $customTemplates[$type->value] ?? null,
                ],
            );
        }
    }
}
