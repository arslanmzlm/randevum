<?php

namespace App\Modules\Medical\Services;

use App\Models\Clinic;
use App\Models\FollowUpType;
use App\Modules\Medical\Repositories\FollowUpTypeRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FollowUpTypeService
{
    public function __construct(
        private FollowUpTypeRepository $repository,
    ) {}

    /**
     * @return LengthAwarePaginator<FollowUpType>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /** The active clinic's type by id, or null — backs the list page's `?edit=` deep link. */
    public function findForActiveClinic(?int $id): ?FollowUpType
    {
        return $id === null || $id <= 0
            ? null
            : $this->repository->find($id);
    }

    /**
     * Active types projected for the create-follow-up dialog select.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function listActiveOptions(): Collection
    {
        return $this->repository->activeForClinic()
            ->map(fn (FollowUpType $type) => ['id' => $type->id, 'name' => $type->name])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FollowUpType
    {
        // clinic_id is auto-set by BelongsToClinic on create; is_system is never
        // request-driven — clinic-created rows are never system rows.
        return $this->repository->create($data + ['is_system' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FollowUpType $followUpType, array $data): FollowUpType
    {
        return $this->repository->update($followUpType, $data);
    }

    /**
     * @throws ValidationException
     */
    public function delete(FollowUpType $followUpType): void
    {
        if ($followUpType->is_system) {
            throw ValidationException::withMessages([
                'name' => [__('follow_up_type.errors.system_not_deletable')],
            ]);
        }

        $this->repository->delete($followUpType);
    }

    /**
     * Provision the default follow-up types for a clinic (new registration or backfill).
     * Idempotent — safe to call multiple times.
     *
     * @param  list<string>  $names
     */
    public function provisionDefaults(Clinic $clinic, array $names): void
    {
        foreach ($names as $name) {
            FollowUpType::withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinic->id, 'name' => $name],
                ['is_system' => true, 'is_active' => true],
            );
        }
    }
}
