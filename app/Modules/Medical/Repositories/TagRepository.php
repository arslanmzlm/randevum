<?php

namespace App\Modules\Medical\Repositories;

use App\Models\Tag;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TagRepository
{
    /**
     * All tags for the active clinic, name-ordered, with a patient count for the
     * management list. ClinicScope on Tag restricts results to the active clinic.
     *
     * @return Collection<int, Tag>
     */
    public function allForActiveClinic(): Collection
    {
        return Tag::withCount('patients')->orderBy('name')->get();
    }

    /**
     * Paginated list for the tags screen — same server-side search/sort contract the other
     * catalog lists use, so the two screens behave identically.
     *
     * @return LengthAwarePaginator<Tag>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(Tag::withCount('patients'))
            ->search('name')
            ->sort('name', 'patients_count', 'created_at')
            ->paginate();
    }

    /** ClinicScope keeps this to the active clinic, so another clinic's id resolves to null. */
    public function find(int $id): ?Tag
    {
        return Tag::withCount('patients')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->fill($data)->save();

        return $tag;
    }

    public function delete(Tag $tag): void
    {
        $tag->delete();
    }
}
