<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fluent server-side filter/sort/paginate wrapper over an Eloquent Builder.
 * Reads PrimeVue-lazy request params (search, sort_field, sort_order, per_page).
 * No clinic logic — ClinicScope on the model already handles tenant isolation.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class FilterHelper
{
    /**
     * @param  Builder<TModel>  $query
     */
    private function __construct(private Builder $query) {}

    /**
     * @param  Builder<TModel>  $query
     * @return self<TModel>
     */
    public static function query(Builder $query): self
    {
        return new self($query);
    }

    /**
     * Apply a LIKE search across the given fields when `request('search')` is non-empty.
     *
     * @return self<TModel>
     */
    public function search(string ...$fields): self
    {
        $term = request('search');

        if (filled($term)) {
            $this->query->where(function (Builder $q) use ($fields, $term): void {
                $first = true;
                foreach ($fields as $field) {
                    if ($first) {
                        $q->whereLike($field, "%{$term}%");
                        $first = false;
                    } else {
                        $q->orWhereLike($field, "%{$term}%");
                    }
                }
            });
        }

        return $this;
    }

    /**
     * Apply sorting from `sort_field` + `sort_order` request params.
     * Falls back to `orderByDesc('id')` when the field is absent or not in the allowed list.
     * sort_order: 1 = ASC, -1 = DESC (PrimeVue convention).
     *
     * @return self<TModel>
     */
    public function sort(string ...$allowed): self
    {
        $field = request('sort_field');
        $order = request('sort_order');

        if ($field && in_array($field, $allowed, true)) {
            $direction = ($order === '-1') ? 'desc' : 'asc';
            $this->query->orderBy($field, $direction);
        } else {
            $this->query->orderByDesc('id');
        }

        return $this;
    }

    /**
     * Apply exact-match filters for any non-null value in the map.
     *
     * @param  array<string, mixed>  $exact  ['field' => value|null]
     * @return self<TModel>
     */
    public function filter(array $exact): self
    {
        foreach ($exact as $field => $value) {
            if (! is_null($value)) {
                $this->query->where($field, $value);
            }
        }

        return $this;
    }

    /**
     * Paginate using the `per_page` request param (capped at the default when absent).
     *
     * @return LengthAwarePaginator<TModel>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        $size = request()->integer('per_page', $perPage);

        return $this->query->paginate($size)->withQueryString();
    }
}
