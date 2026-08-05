<?php

namespace App\Modules\Medical\Services;

use App\Models\Tag;
use App\Modules\Medical\Repositories\TagRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TagService
{
    public function __construct(
        private TagRepository $repository,
    ) {}

    /**
     * @return Collection<int, Tag>
     */
    public function listForActiveClinic(): Collection
    {
        return $this->repository->allForActiveClinic();
    }

    /**
     * @return LengthAwarePaginator<Tag>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /**
     * Active-clinic tags projected for a filter/picker option list (list toolbar,
     * patient detail add-tag picker) — id/name/color only, no patient count.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, color: string}>
     */
    public function listOptionsForActiveClinic(): \Illuminate\Support\Collection
    {
        return $this->repository->allForActiveClinic()
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag
    {
        // clinic_id is auto-set by BelongsToClinic on create.
        return $this->repository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        return $this->repository->update($tag, $data);
    }

    public function delete(Tag $tag): void
    {
        $this->repository->delete($tag);
    }
}
