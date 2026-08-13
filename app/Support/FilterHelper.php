<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fluent server-side filter/sort/paginate wrapper over an Eloquent Builder.
 *
 * Reads JSON:API-style request params: filters are namespaced under `filter[...]`
 * (e.g. `?filter[gender]=male`), sorting is a single `sort` param with a `-` prefix
 * for descending (`?sort=-created_at`). Pagination stays flat (`page` / `per_page`)
 * to match Laravel's paginator and PrimeVue's lazy events. Each method applies its
 * constraint only when a usable value is present, so an absent param is a no-op.
 *
 * No tenant logic lives here: ClinicScope on the model already isolates clinics.
 *
 * @template TModel of Model
 */
class FilterHelper
{
    /** Sentinel member of a value-set filter meaning "this column IS NULL" ("Belirtilmemiş"). */
    public const NONE = 'none';

    /**
     * @param  Builder<TModel>  $query
     */
    private function __construct(private Builder $query) {}

    /**
     * Start a filter chain for a model class or an already-constrained builder.
     *
     * @param  Builder<TModel>|class-string<TModel>  $subject
     * @return self<TModel>
     */
    public static function for(Builder|string $subject): self
    {
        return new self(is_string($subject) ? $subject::query() : $subject);
    }

    /**
     * Apply a LIKE search across the given fields when `filter[search]` is non-empty.
     *
     * A field given as an array is matched as those columns joined by a space, so a full-name
     * query hits a first_name/last_name pair. Matching is Turkish case/diacritic insensitive
     * (see SearchTerm).
     *
     * @param  string|list<string>  ...$fields
     * @return self<TModel>
     */
    public function search(string|array ...$fields): self
    {
        $term = request()->input('filter.search');

        if (blank($term) || ! is_string($term) || $fields === []) {
            return $this;
        }

        $this->query->where(fn (Builder $query) => $this->applyLike($query, $fields, $term));

        return $this;
    }

    /**
     * Apply a LIKE search across columns on a related model when `filter[search]` is non-empty.
     * Mirrors search() but constrains a relation — use when the searchable fields live on a
     * related row (e.g. patient name/phone on the `patients` relation, not the owning table).
     *
     * @param  string|list<string>  ...$fields
     * @return self<TModel>
     */
    public function searchRelation(string $relation, string|array ...$fields): self
    {
        return $this->applySearchRelation($relation, $fields, withTrashed: false);
    }

    /**
     * searchRelation() over a relation whose rows may be soft-deleted — for a list that keeps
     * showing a row after its related record is trashed (a treatment/appointment outlives its
     * patient), so searching by that name must still find it.
     *
     * Opt-in per caller rather than automatic: withTrashed() only exists on a SoftDeletes model,
     * and including trashed rows is a list-specific decision, not a property of every relation.
     *
     * @param  string|list<string>  ...$fields
     * @return self<TModel>
     */
    public function searchRelationWithTrashed(string $relation, string|array ...$fields): self
    {
        return $this->applySearchRelation($relation, $fields, withTrashed: true);
    }

    /**
     * @param  list<string|list<string>>  $fields
     * @return self<TModel>
     */
    private function applySearchRelation(string $relation, array $fields, bool $withTrashed): self
    {
        $term = request()->input('filter.search');

        if (blank($term) || ! is_string($term) || $fields === []) {
            return $this;
        }

        $this->query->whereHas($relation, function (Builder $query) use ($fields, $term, $withTrashed): void {
            if ($withTrashed) {
                $query->withTrashed();
            }

            $this->applyLike($query, $fields, $term);
        });

        return $this;
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string|list<string>>  $fields
     */
    private function applyLike(Builder $query, array $fields, string $term): void
    {
        $needle = '%'.SearchTerm::normalize($term).'%';

        foreach ($fields as $index => $field) {
            $relation = $this->relationOf($field);

            if ($relation !== null) {
                [$name, $columns] = $relation;

                $index === 0
                    ? $query->whereHas($name, fn (Builder $related) => $related->whereLike(SearchTerm::column($columns), $needle))
                    : $query->orWhereHas($name, fn (Builder $related) => $related->whereLike(SearchTerm::column($columns), $needle));

                continue;
            }

            $index === 0
                ? $query->whereLike(SearchTerm::column($field), $needle)
                : $query->orWhereLike(SearchTerm::column($field), $needle);
        }
    }

    /**
     * A dotted field (`patient.first_name`) searches a related row instead of this table, so one
     * search box can span both (an SMS log's own phone OR its patient's name). An array of dotted
     * fields must name the same relation and is matched as those columns joined by a space.
     *
     * @param  string|list<string>  $field
     * @return array{0: string, 1: string|list<string>}|null
     */
    private function relationOf(string|array $field): ?array
    {
        $first = is_array($field) ? ($field[0] ?? '') : $field;

        if (! str_contains($first, '.')) {
            return null;
        }

        [$relation] = explode('.', $first, 2);

        $strip = fn (string $value): string => explode('.', $value, 2)[1];

        return [
            $relation,
            is_array($field) ? array_map($strip, $field) : $strip($field),
        ];
    }

    /**
     * Apply sorting from the `sort` param (`name` asc, `-name` desc), restricted to
     * the allowed columns. Falls back to `orderByDesc('id')` otherwise.
     *
     * `id` is always appended as a secondary key, same direction as the primary column
     * (mirrors the id tie-breaker repositories already hand-roll for their default
     * ordering, e.g. TreatmentRepository/TransactionRepository): the primary column alone
     * is not a total order (repeated `first_name`, low-cardinality `is_active`, …), so
     * without it LIMIT/OFFSET paging can repeat or skip rows across pages.
     *
     * @return self<TModel>
     */
    public function sort(string ...$allowed): self
    {
        $sort = request()->input('sort');

        if (is_string($sort) && $sort !== '') {
            $field = ltrim($sort, '-');

            if (in_array($field, $allowed, true)) {
                $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

                $this->query->orderBy($field, $direction)->orderBy('id', $direction);

                return $this;
            }
        }

        $this->query->orderByDesc('id');

        return $this;
    }

    /**
     * Exact-match filter reading `filter[<column>]` from the request. Columns ending
     * in `_id` are treated as integer foreign keys: a non-numeric value is ignored
     * (guards against a SQL error on a bigint column) and the value is cast to int.
     * Other columns match the raw value. Empty/absent params are no-ops.
     *
     * @return self<TModel>
     */
    public function exact(string ...$columns): self
    {
        foreach ($columns as $column) {
            $value = request()->input("filter.{$column}");

            if (blank($value)) {
                continue;
            }

            if (str_ends_with($column, '_id')) {
                if (! is_numeric($value)) {
                    continue;
                }

                $this->query->where($column, (int) $value);
            } else {
                $this->query->where($column, $value);
            }
        }

        return $this;
    }

    /**
     * Multi-value filter over a comma-separated `filter[<column>]` (`?filter[service_id]=4,7`).
     * The `none` member matches NULL, so a nullable column can be filtered on "unspecified"
     * alone or together with real values. `_id` columns are cast to int (non-numeric members
     * dropped); a single value behaves like exact(). Empty/absent params are no-ops.
     *
     * @return self<TModel>
     */
    public function multiple(string ...$columns): self
    {
        foreach ($columns as $column) {
            $raw = request()->input("filter.{$column}");

            if (blank($raw) || ! is_string($raw)) {
                continue;
            }

            $members = array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $m): bool => $m !== ''));

            $includeNull = in_array(self::NONE, $members, true);
            $values = array_values(array_filter($members, static fn (string $m): bool => $m !== self::NONE));

            if (str_ends_with($column, '_id')) {
                $values = array_values(array_map(static fn (string $v): int => (int) $v, array_filter($values, 'is_numeric')));
            }

            $this->applyAnyOf($column, $values, $includeNull);
        }

        return $this;
    }

    /**
     * Multi-value filter over a related model's column via a comma-separated
     * `filter[<param ?? column>]` — for a many-row relation (e.g. a treatment's service
     * lines) where multiple()'s own-column `whereIn` doesn't apply. Mirrors multiple()'s
     * semantics exactly: `_id`-suffixed columns are cast to int (non-numeric members
     * dropped), the `none` member matches rows with NO related row at all
     * (`whereDoesntHave`), and everything is grouped inside one `where()` so it composes
     * safely with the rest of the query. Empty/absent param is a no-op.
     *
     * @return self<TModel>
     */
    public function multipleRelation(string $relation, string $column, ?string $param = null): self
    {
        $raw = request()->input('filter.'.($param ?? $column));

        if (blank($raw) || ! is_string($raw)) {
            return $this;
        }

        $members = array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $m): bool => $m !== ''));

        $includeNone = in_array(self::NONE, $members, true);
        $values = array_values(array_filter($members, static fn (string $m): bool => $m !== self::NONE));

        if (str_ends_with($column, '_id')) {
            $values = array_values(array_map(static fn (string $v): int => (int) $v, array_filter($values, 'is_numeric')));
        }

        if ($values === [] && ! $includeNone) {
            return $this;
        }

        $this->query->where(function (Builder $query) use ($relation, $column, $values, $includeNone): void {
            if ($values !== []) {
                $query->whereHas($relation, fn (Builder $r) => $r->whereIn($column, $values));
            }

            if ($includeNone) {
                $query->orWhereDoesntHave($relation);
            }
        });

        return $this;
    }

    /**
     * Shared primitive behind multiple()/enumMultiple()/multipleRelation(): match any of
     * $values on $column, OR NULL when $includeNull is true. No-op when there is nothing
     * to apply.
     *
     * @param  list<mixed>  $values
     */
    private function applyAnyOf(string $column, array $values, bool $includeNull): void
    {
        if ($values === [] && ! $includeNull) {
            return;
        }

        $this->query->where(function (Builder $query) use ($column, $values, $includeNull): void {
            if ($values !== []) {
                $query->whereIn($column, $values);
            }

            if ($includeNull) {
                $query->orWhereNull($column);
            }
        });
    }

    /**
     * Three-state boolean filter from `filter[<column>]`: filter when present and
     * non-empty (`1`→true, `0`→false), skip when absent — so "all / only-true /
     * only-false" are all expressible.
     *
     * @return self<TModel>
     */
    public function boolean(string ...$columns): self
    {
        foreach ($columns as $column) {
            $key = "filter.{$column}";

            if (request()->has($key) && request()->input($key) !== '') {
                $this->query->where($column, request()->boolean($key));
            }
        }

        return $this;
    }

    /**
     * Exact-match enum filter from `filter[<column>]`, validated against its backed
     * enum; an absent or invalid value is ignored. The raw value `none` matches NULL
     * instead (see `NONE`).
     *
     * @param  array<string, class-string<BackedEnum>>  $map  ['column' => Enum::class]
     * @return self<TModel>
     */
    public function enum(array $map): self
    {
        foreach ($map as $column => $enum) {
            $raw = request()->input("filter.{$column}");

            if ($raw === self::NONE) {
                $this->query->whereNull($column);

                continue;
            }

            $value = request()->enum("filter.{$column}", $enum);

            if ($value !== null) {
                $this->query->where($column, $value->value);
            }
        }

        return $this;
    }

    /**
     * Multi-value enum filter from a comma-separated `filter[<column>]`
     * (`?filter[status]=open,closed`). Invalid members are dropped; the `none` member
     * matches NULL (see `NONE`); empty/absent is a no-op.
     *
     * @param  array<string, class-string<BackedEnum>>  $map  ['column' => Enum::class]
     * @return self<TModel>
     */
    public function enumMultiple(array $map): self
    {
        foreach ($map as $column => $enum) {
            $raw = request()->input("filter.{$column}");

            if (blank($raw) || ! is_string($raw)) {
                continue;
            }

            $members = array_map(trim(...), explode(',', $raw));
            $includeNull = in_array(self::NONE, $members, true);

            $values = array_values(array_filter(array_map(
                static fn (string $case): ?BackedEnum => $enum::tryFrom($case),
                $members,
            )));

            $this->applyAnyOf($column, array_map(static fn (BackedEnum $e) => $e->value, $values), $includeNull);
        }

        return $this;
    }

    /**
     * Exact-day filter on date/datetime columns: matches rows whose `$column` falls
     * on the date given by `filter[<column>]`. Unparseable input is ignored.
     *
     * @return self<TModel>
     */
    public function date(string ...$columns): self
    {
        foreach ($columns as $column) {
            $value = rescue(fn () => request()->date("filter.{$column}"), null, report: false);

            if ($value !== null) {
                $this->query->whereDate($column, $value);
            }
        }

        return $this;
    }

    /**
     * Inclusive date-range filter on a single column, bounded by `filter[<startParam>]`
     * and `filter[<endParam>]`. Day boundaries resolve in `$timezone` (default: app
     * timezone) — pass the clinic timezone for clinic-local ranges. Either bound may
     * be omitted.
     *
     * @return self<TModel>
     */
    public function dateRange(
        string $column,
        string $startParam = 'start_date',
        string $endParam = 'end_date',
        ?string $timezone = null,
    ): self {
        $tz = $timezone ?? config('app.timezone');

        $start = rescue(fn () => request()->date("filter.{$startParam}", tz: $tz), null, report: false);
        $end = rescue(fn () => request()->date("filter.{$endParam}", tz: $tz), null, report: false);

        if ($start !== null) {
            $this->query->where($column, '>=', $start->startOfDay());
        }

        if ($end !== null) {
            $this->query->where($column, '<=', $end->endOfDay());
        }

        return $this;
    }

    /**
     * Soft-delete visibility toggle from `filter[<param>]` (model must use SoftDeletes):
     * `with` includes trashed rows, `only` returns just trashed; absent keeps the
     * default scope.
     *
     * @return self<TModel>
     */
    public function trashed(string $param = 'trashed'): self
    {
        match (request()->input("filter.{$param}")) {
            'with' => $this->query->withTrashed(),
            'only' => $this->query->onlyTrashed(),
            default => null,
        };

        return $this;
    }

    /**
     * Build the JSON:API list-state prop the frontend re-seeds from (same shape as the
     * URL query and the serialized reload payload): the `filter` bag (`search` is always
     * included; each declared filter is read from `filter[<column>]` and cast by type),
     * the single `sort` token, and `per_page`. A `boolean`/`integer` filter is `null`
     * when absent (three-state); a `string`/`date` filter defaults to `''`; an `array`
     * filter defaults to `[]`.
     *
     * @param  array<string, 'string'|'boolean'|'integer'|'array'|'date'>  $filters  column => type
     * @return array{filter: array<string, mixed>, sort: string, per_page: int}
     */
    public static function requestState(array $filters = [], int $perPage = 20): array
    {
        $bag = ['search' => request()->input('filter.search', '')];

        foreach ($filters as $column => $type) {
            $key = "filter.{$column}";
            $present = request()->has($key) && request()->input($key) !== '';

            $bag[$column] = match ($type) {
                'boolean' => $present ? request()->boolean($key) : null,
                'integer' => $present ? request()->integer($key) : null,
                'array' => $present && is_string(request()->input($key))
                    ? explode(',', (string) request()->input($key))
                    : [],
                'date' => $present ? request()->input($key) : null,
                default => request()->input($key, ''),
            };
        }

        return [
            'filter' => $bag,
            'sort' => request()->input('sort', ''),
            'per_page' => request()->integer('per_page', $perPage),
        ];
    }

    /**
     * Paginate using the `per_page` request param (falling back to the default).
     *
     * @return LengthAwarePaginator<TModel>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate(request()->integer('per_page', $perPage))->withQueryString();
    }

    /**
     * Resolve the built query as a full collection (no pagination).
     *
     * @return Collection<int, TModel>
     */
    public function get(): Collection
    {
        return $this->query->get();
    }
}
