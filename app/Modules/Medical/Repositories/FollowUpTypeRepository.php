<?php

namespace App\Modules\Medical\Repositories;

use App\Models\FollowUpType;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class FollowUpTypeRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on FollowUpType restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<FollowUpType>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(FollowUpType::class)
            ->search('name')
            ->sort('name', 'is_active', 'created_at')
            ->boolean('is_active')
            ->paginate();
    }

    /**
     * Active types for the active clinic, ordered by name.
     *
     * @return Collection<int, FollowUpType>
     */
    public function activeForClinic(): Collection
    {
        return FollowUpType::active()->orderBy('name')->get();
    }

    /** ClinicScope keeps this to the active clinic, so another clinic's id resolves to null. */
    public function find(int $id): ?FollowUpType
    {
        return FollowUpType::find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FollowUpType
    {
        return FollowUpType::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FollowUpType $followUpType, array $data): FollowUpType
    {
        $followUpType->fill($data)->save();

        return $followUpType;
    }

    public function delete(FollowUpType $followUpType): void
    {
        $followUpType->delete();
    }
}
