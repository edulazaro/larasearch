<?php

namespace EduLazaro\Larasearch\Models;

use EduLazaro\Larasearch\Exceptions\MissingVisibility;
use EduLazaro\Larasearch\Larasearch;
use EduLazaro\Larasearch\Support\Matcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The global index: one row per record that shows itself in a command palette, across every
 * model, with what the palette paints (title, subtitle) so it never loads the records.
 *
 *     Index::searchText('acme')->in($organization)->for($user)->limit(10)->get();
 *
 * `in()` keeps a tenant's rows; `for()` keeps, type by type, what the user may see, asking
 * each model's `searchableFor($user)` at search time: who sees what is never stored here, so
 * reassigning a record needs no reindex.
 *
 * @property string $searchable_type
 * @property string $searchable_id
 * @property string $scope_type
 * @property string $scope_id
 * @property string $title
 * @property string|null $subtitle
 * @property string $search_text
 */
class Index extends Model
{
    protected $table = 'searchables';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'searchable_type',
        'searchable_id',
        'scope_type',
        'scope_id',
        'title',
        'subtitle',
        'search_text',
    ];

    /**
     * The record behind the row.
     *
     * @return MorphTo<Model, $this>
     */
    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every word of the term, in any order, as the models' own searchText().
     *
     * @param  Builder<Index>  $query
     * @param  string|null  $term
     * @return Builder<Index>
     */
    public function scopeSearchText(Builder $query, ?string $term): Builder
    {
        Matcher::apply($query, $this->qualifyColumn('search_text'), $term);

        return $query;
    }

    /**
     * One tenant's rows, or several tenants' (a director over many offices). Null means the
     * rows with no tenant.
     *
     * @param  Builder<Index>  $query
     * @param  Model|iterable<Model>|null  $scope
     * @return Builder<Index>
     */
    public function scopeIn(Builder $query, Model|iterable|null $scope): Builder
    {
        $scopes = $scope instanceof Model || $scope === null ? [$scope] : $scope;

        return $query->where(function (Builder $query) use ($scopes) {
            foreach ($scopes as $one) {
                $query->orWhere(fn (Builder $query) => $query->where(Larasearch::scopeColumns($one)));
            }
        });
    }

    /**
     * Only what the user may see: for each model present, the rows whose record is in that
     * model's `searchableFor($user)`. A model in the index without it fails loudly
     * (`MissingVisibility`) rather than showing everything.
     *
     * @param  Builder<Index>  $query
     * @param  mixed  $user
     * @return Builder<Index>
     */
    public function scopeFor(Builder $query, mixed $user): Builder
    {
        $types = (clone $query)->reorder()->distinct()->pluck('searchable_type');
        $cast = $query->getConnection()->getDriverName() === 'mysql' ? 'char' : 'varchar';

        return $query->where(function (Builder $query) use ($types, $user, $cast) {
            $query->whereRaw('1 = 0');

            foreach ($types as $type) {
                $class = Relation::getMorphedModel($type) ?? $type;

                if (! method_exists($class, 'searchableFor')) {
                    throw MissingVisibility::on($class);
                }

                $visible = $class::searchableFor($user);
                $key = $visible->getModel()->getQualifiedKeyName();

                // The ids are kept as text (numeric, UUID or ULID alike): the subquery says them
                // as text too, which PostgreSQL requires and MySQL and SQLite accept.
                $query->orWhere(fn (Builder $query) => $query
                    ->where('searchable_type', $type)
                    ->whereIn('searchable_id', $visible->toBase()->selectRaw("cast({$key} as {$cast})")));
            }
        });
    }

    /**
     * Where the result links to, from the model's route and the key, without loading the
     * record (unless the model builds its link itself).
     *
     * @return string|null
     */
    public function url(): ?string
    {
        $class = Relation::getMorphedModel($this->searchable_type) ?? $this->searchable_type;

        if (! class_exists($class)) {
            return null;
        }

        return $class::overridesSearchableUrl()
            ? $this->searchable?->searchableUrl()
            : (new $class)->searchableUrl($this->searchable_id);
    }
}
