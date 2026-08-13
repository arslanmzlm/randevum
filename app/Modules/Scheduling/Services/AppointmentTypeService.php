<?php

namespace App\Modules\Scheduling\Services;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Modules\Scheduling\Contracts\AppointmentTypeLookupContract;
use App\Modules\Scheduling\Repositories\AppointmentTypeRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class AppointmentTypeService implements AppointmentTypeLookupContract
{
    public function __construct(
        private AppointmentTypeRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return LengthAwarePaginator<AppointmentType>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /** The active clinic's type by id, or null — backs the list page's `?edit=` deep link. */
    public function findForActiveClinic(?int $id): ?AppointmentType
    {
        return $id === null || $id <= 0
            ? null
            : $this->repository->find($id);
    }

    /**
     * Active types for the booking form select, ordered by name.
     *
     * @return Collection<int, AppointmentType>
     */
    public function listActiveForClinic(): Collection
    {
        return $this->repository->activeForClinic();
    }

    /**
     * Active types projected for the booking form select, ordered by name.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, color: string|null, default_duration_minutes: int|null}>
     */
    public function listActiveForBooking(): \Illuminate\Support\Collection
    {
        return $this->repository->activeForClinic()
            ->map(fn (AppointmentType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
                'default_duration_minutes' => $t->default_duration_minutes,
            ])
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, color: string|null}>
     */
    public function activeForTreatment(): \Illuminate\Support\Collection
    {
        return $this->repository->activeForClinic()
            ->map(fn (AppointmentType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AppointmentType
    {
        $clinic = $this->clinicContext->clinicOrFail();
        $data['vertical_id'] = $clinic->vertical_id;

        // clinic_id is auto-set by BelongsToClinic on create — not set manually.
        return $this->repository->create($data);
    }

    /**
     * Provision default appointment types for a newly created clinic.
     * Reads the vertical's config; if no appointment_types key is set, provisions nothing.
     * Safe to call multiple times — firstOrCreate is idempotent.
     *
     * @param  array{name: string, color: string, default_duration_minutes: int}[]  $defaults
     */
    public function provisionDefaults(Clinic $clinic, array $defaults): void
    {
        foreach ($defaults as $item) {
            AppointmentType::withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinic->id, 'name' => $item['name']],
                array_merge($item, [
                    'vertical_id' => $clinic->vertical_id,
                    'is_active' => true,
                ]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AppointmentType $appointmentType, array $data): AppointmentType
    {
        return $this->repository->update($appointmentType, $data);
    }

    public function delete(AppointmentType $appointmentType): void
    {
        $this->repository->delete($appointmentType);
    }
}
