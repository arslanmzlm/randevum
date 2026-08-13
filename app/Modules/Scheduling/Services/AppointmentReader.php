<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Modules\Core\Contracts\PatientAppointmentsContract;
use App\Modules\Core\Contracts\UpcomingAppointmentsContract;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Thin read-only seam for the appointment lists other modules consume.
 * Split out of AppointmentService so the cross-module bindings never resolve the
 * orchestration service (which reaches back into Medical through PatientRegistrarContract).
 * Never add a dependency beyond the repository here.
 */
class AppointmentReader implements PatientAppointmentsContract, UpcomingAppointmentsContract
{
    /**
     * Statuses an appointment still counts as upcoming in.
     *
     * @var list<AppointmentStatus>
     */
    private const UPCOMING_STATUSES = [
        AppointmentStatus::Confirmed,
        AppointmentStatus::Rescheduled,
    ];

    public function __construct(
        private AppointmentRepository $repository,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function listForPatient(Patient $patient, User $user): Collection
    {
        $doctorIds = $this->visibleDoctorIds($user);

        if ($doctorIds === []) {
            return new Collection;
        }

        return $this->repository->forPatient($patient->id, $doctorIds[0] ?? null);
    }

    /**
     * {@inheritDoc}
     */
    public function upcomingFor(User $user, int $limit): array
    {
        $appointments = $this->repository->upcomingForDoctors(
            $this->visibleDoctorIds($user),
            self::UPCOMING_STATUSES,
            $limit,
        );

        return $appointments->map(fn (Appointment $a) => [
            'id' => $a->id,
            'patient_id' => $a->patient_id,
            'patient_name' => trim($a->patient->first_name.' '.$a->patient->last_name),
            'patient_is_deleted' => $a->patient->trashed(),
            'doctor_id' => $a->doctor_id,
            'doctor_name' => $a->doctor->display_name,
            'doctor_is_deleted' => $a->doctor->trashed(),
            'service_name' => $a->service?->name,
            'appointment_type' => $a->appointmentType
                ? ['name' => $a->appointmentType->name, 'color' => $a->appointmentType->color]
                : null,
            'status' => $a->status->value,
            'is_walk_in' => $a->is_walk_in,
            'starts_at' => $a->starts_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Doctor scope for the reading user.
     *
     * null → every clinic doctor (`appointments.viewAll`)
     * [id] → the user's own doctor profile
     * []   → no doctor profile, so nothing is visible
     *
     * @return list<int>|null
     */
    private function visibleDoctorIds(User $user): ?array
    {
        if ($user->can('appointments.viewAll')) {
            return null;
        }

        $ownId = $user->doctor?->id;

        return $ownId !== null ? [$ownId] : [];
    }
}
