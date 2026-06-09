<?php

namespace App\Modules\Core\Services;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use App\Modules\Core\Contracts\AppointmentCancellationContract;
use App\Modules\Core\Repositories\DoctorRepository;
use App\Support\ClinicContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DoctorProfileService
{
    public function __construct(
        private DoctorRepository $repository,
        private ClinicContext $clinicContext,
        private AppointmentCancellationContract $cancellation,
    ) {}

    /**
     * Create a new user + clinic-scoped doctor role + doctor profile in one transaction.
     *
     * Clinic locale/timezone are copied from the active clinic row. The user is
     * immediately email-verified because the owner vouches for the account.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws \Throwable
     */
    public function addDoctor(array $validated): Doctor
    {
        return DB::transaction(function () use ($validated): Doctor {
            $clinic = Clinic::findOrFail($this->clinicContext->id());

            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'locale' => $clinic->locale,
                'timezone' => $clinic->timezone,
            ]);

            // email_verified_at is not in $fillable (security) — set directly.
            $user->email_verified_at = now();
            $user->saveQuietly();

            // SetClinicContext already called setPermissionsTeamId($clinicId),
            // so assignRole scopes the role to the active clinic.
            $user->assignRole('doctor');

            return $this->repository->create([
                'user_id' => $user->id,
                'title' => $validated['title'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'license_number' => $validated['license_number'] ?? null,
                'certificate' => $validated['certificate'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Create the owner's own doctor profile. The clinic_id is auto-filled by
     * BelongsToClinic; user_id comes from the authenticated user.
     *
     * @throws ValidationException when the user already has a doctor profile.
     */
    public function createOwnProfile(User $user): Doctor
    {
        if ($user->doctor()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages([
                'user_id' => [__('messages.doctor.already_has_profile')],
            ]);
        }

        return $this->repository->create(['user_id' => $user->id]);
    }

    /**
     * Apply validated profile fields to a doctor.
     *
     * Strips is_active when the editing user is not an owner/manager.
     * Propagates first_name/last_name to the linked user row.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(Doctor $doctor, array $validated, bool $canManage): void
    {
        if (! $canManage) {
            unset($validated['is_active']);
        }

        $userFields = array_intersect_key($validated, array_flip(['first_name', 'last_name']));
        if (! empty($userFields)) {
            $doctor->user->fill($userFields)->save();
        }

        $doctorFields = array_diff_key($validated, array_flip(['first_name', 'last_name']));
        $this->repository->update($doctor, $doctorFields);
    }

    /**
     * Revoke the clinic-scoped doctor role and soft-delete the profile.
     *
     * @throws \Throwable
     */
    public function remove(Doctor $doctor): void
    {
        DB::transaction(function () use ($doctor): void {
            // SetClinicContext already set the Spatie team context to the active clinic,
            // so removeRole revokes only the clinic-scoped assignment.
            $doctor->user->removeRole('doctor');
            $this->repository->delete($doctor);
        });
    }

    /**
     * Offboard a doctor: optionally cancel future appointments, mark departed, revoke role.
     * All three steps run in one DB transaction and return the cancellation count.
     *
     * Guards (throws ValidationException, nothing mutated):
     *   - actor trying to offboard themselves
     *   - doctor already offboarded
     *   - unchecked cancel_appointments while future appointments still exist
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function offboard(Doctor $doctor, bool $cancelAppointments, ?string $reason, User $actor): int
    {
        if ($doctor->user_id === $actor->id) {
            throw ValidationException::withMessages([
                'cancel_appointments' => [__('messages.doctor.cannot_offboard_self')],
            ]);
        }

        if ($doctor->left_at !== null) {
            throw ValidationException::withMessages([
                'cancel_appointments' => [__('messages.doctor.already_offboarded')],
            ]);
        }

        if (! $cancelAppointments && $this->cancellation->countCancellableFutureForDoctor($doctor->id) > 0) {
            throw ValidationException::withMessages([
                'cancel_appointments' => [__('messages.doctor.has_upcoming_appointments')],
            ]);
        }

        return DB::transaction(function () use ($doctor, $cancelAppointments, $reason, $actor): int {
            $count = $cancelAppointments
                ? $this->cancellation->cancelFutureForDoctor($doctor->id, $reason, $actor)
                : 0;

            $this->repository->update($doctor, ['is_active' => false, 'left_at' => now()]);

            // SetClinicContext already set the Spatie team context to the active clinic,
            // so removeRole revokes only the clinic-scoped assignment.
            $doctor->user->removeRole('doctor');

            return $count;
        });
    }

    /**
     * Return the upcoming-appointments count for the offboard preview dialog.
     * Read-only — does not mutate state.
     */
    public function previewOffboard(Doctor $doctor): int
    {
        return $this->cancellation->countCancellableFutureForDoctor($doctor->id);
    }
}
