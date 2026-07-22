<?php

namespace App\Modules\Medical\Repositories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

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
