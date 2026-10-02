<?php

namespace EduLazaro\Larasearch\Concerns;

use EduLazaro\Larasearch\Exceptions\MissingSearchColumn;
use EduLazaro\Larasearch\Larasearch;
use EduLazaro\Larasearch\Models\Index;
use EduLazaro\Larasearch\Support\Matcher;
use EduLazaro\Larasearch\Support\Relations;
use EduLazaro\Larasearch\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Text search on a model, with two uses:
 *
 * - Its lists: the fields in `$searchable` are kept, joined and normalized, in the model's
 *   own `search_text` column (add it with `$table->searchable()`), and `searchText($term)`
 *   filters on it inside any query.
 * - The global index (a command palette): with `$searchableTitle` and `$searchableRoute`, the
 *   model also keeps a row in `searchables`, scoped by tenant and filtered by
 *   `searchableFor($user)`. See `Models\Index`.
 *
 *     protected array $searchable = ['name', 'company', 'email', 'tasks.title'];
 *     protected string $searchableTitle = 'name';
 *     protected ?string $searchableSubtitle = 'company';
 *     protected string $searchableRoute = 'projects.show';
 *
 * A field with a dot is read through a relation ("tasks.title"): when one of those related
 * records is saved or deleted, the model is indexed again (`Support\Relations`). Changes
 * made around Eloquent (a query builder `update()`, a pivot attach) fire no event: run
 * `php artisan search:reindex` after them.
 *
 * @mixin Model
 */
trait HasSearch
{
    /**
     * Whether each table has its search_text column, asked once per table.
     *
     * @var array<string, bool>
     */
    private static array $searchColumns = [];

    /**
     * @return void
     */
    public static function bootHasSearch(): void
    {
        static::saving(function (Model $model) {
            $model->ensureSearchColumn();

            // Only when it can have changed: a status changed alone costs nothing.
            if (! $model->exists || $model->isDirty($model->searchableColumns())) {
                $model->setAttribute('search_text', $model->searchableText());
            }
        });

        static::saved(function (Model $model) {
            if ($model->wasRecentlyCreated || $model->wasChanged([...$model->searchableColumns(), ...$model->searchableIndexColumns()])) {
                $model->syncSearchIndex();
            }
        });

        static::deleted(fn (Model $model) => $model->forgetSearchIndex());

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(fn (Model $model) => $model->syncSearchIndex());
        }

        // Dotted fields: saving a related record indexes this one again (Support\Relations).
        Relations::register(static::class);
    }

    /**
     * Only the records whose text holds every word of the term, in any order and in any of
     * the fields. An empty term leaves the query alone, so a filter can always pass it on.
     *
     * @param  Builder<static>  $query
     * @param  string|null  $term
     * @return Builder<static>
     */
    public function scopeSearchText(Builder $query, ?string $term): Builder
    {
        $this->ensureSearchColumn();

        Matcher::apply($query, $this->qualifyColumn('search_text'), $term);

        return $query;
    }

    /**
     * The fields the text is made of.
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        return property_exists($this, 'searchable') ? array_values($this->searchable) : [];
    }

    /**
     * The model's own columns among the fields (a dotted field is a relation's).
     *
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        return array_values(array_filter($this->searchableFields(), fn (string $field) => ! str_contains($field, '.')));
    }

    /**
     * The text kept in search_text and in the index: the fields' values, joined and normalized.
     * Override it for a text that is not a list of fields.
     *
     * @return string
     */
    public function searchableText(): string
    {
        $values = [];

        foreach ($this->searchableFields() as $field) {
            array_push($values, ...self::valuesAt($this, explode('.', $field)));
        }

        return Text::normalize(implode(' ', array_filter($values, fn ($value) => $value !== null && $value !== '')));
    }

    /**
     * What the global index shows as the result's name. The field in `$searchableTitle`;
     * override it for a computed one.
     *
     * @return string|null
     */
    public function searchableTitle(): ?string
    {
        $field = property_exists($this, 'searchableTitle') ? $this->searchableTitle : null;

        return $field ? (string) $this->getAttribute($field) : null;
    }

    /**
     * The second line of a result: the field in `$searchableSubtitle`, if any.
     *
     * @return string|null
     */
    public function searchableSubtitle(): ?string
    {
        $field = property_exists($this, 'searchableSubtitle') ? $this->searchableSubtitle : null;
        $value = $field ? $this->getAttribute($field) : null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The route a result links to, given the record's key.
     *
     * @return string|null
     */
    public function searchableRoute(): ?string
    {
        return property_exists($this, 'searchableRoute') ? $this->searchableRoute : null;
    }

    /**
     * Where a result links to. From `$searchableRoute` and the key; override it when the
     * link is not a route of the record.
     *
     * @param  int|string|null  $key  The record's key, when there is no loaded model.
     * @return string|null
     */
    public function searchableUrl(int|string|null $key = null): ?string
    {
        $route = $this->searchableRoute();

        return $route ? route($route, $key ?? $this->getKey()) : null;
    }

    /**
     * Whether the model keeps a row in the global index: it says how to show itself.
     *
     * @return bool
     */
    public function isGloballySearchable(): bool
    {
        return $this->searchableTitle() !== null
            && ($this->searchableRoute() !== null || static::overridesSearchableUrl());
    }

    /**
     * The model's tenant in the global index: `Larasearch::resolveScopeUsing()` unless the
     * model overrides this.
     *
     * @return Model|null
     */
    public function searchableScope(): ?Model
    {
        return Larasearch::scopeOf($this);
    }

    /**
     * Writes the text again, and the index row, without touching updated_at or firing the
     * model's events: what `search:reindex` and a changed relation do.
     *
     * @return void
     */
    public function reindexSearch(): void
    {
        $this->ensureSearchColumn();

        $text = $this->searchableText();

        $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->update(['search_text' => $text]);
        $this->setAttribute('search_text', $text);
        $this->syncOriginalAttribute('search_text');

        $this->syncSearchIndex();
    }

    /**
     * The model's row in the global index: written when it shows itself there, removed when
     * it no longer does.
     *
     * @return void
     */
    public function syncSearchIndex(): void
    {
        if (! $this->isGloballySearchable()) {
            $this->forgetSearchIndex();

            return;
        }

        Index::query()->updateOrCreate(
            ['searchable_type' => $this->getMorphClass(), 'searchable_id' => (string) $this->getKey()],
            [
                ...Larasearch::scopeColumns($this->searchableScope()),
                'title' => mb_substr((string) $this->searchableTitle(), 0, 255),
                'subtitle' => ($subtitle = $this->searchableSubtitle()) === null ? null : mb_substr($subtitle, 0, 255),
                'search_text' => $this->searchableText(),
            ],
        );
    }

    /**
     * @return void
     */
    public function forgetSearchIndex(): void
    {
        Index::query()
            ->where('searchable_type', $this->getMorphClass())
            ->where('searchable_id', (string) $this->getKey())
            ->delete();
    }

    /**
     * Fails early and clearly when the table has no search_text column, instead of an SQL
     * error in the middle of a save or a list.
     *
     * @return void
     */
    public function ensureSearchColumn(): void
    {
        $key = $this->getConnectionName().'|'.$this->getTable();

        self::$searchColumns[$key] ??= Schema::connection($this->getConnectionName())->hasColumn($this->getTable(), 'search_text');

        if (! self::$searchColumns[$key]) {
            throw MissingSearchColumn::on(static::class, $this->getTable());
        }
    }

    /**
     * Forgets what is known about the tables' columns (for tests that build tables).
     *
     * @return void
     */
    public static function flushSearchColumns(): void
    {
        self::$searchColumns = [];
    }

    /**
     * The columns the index row is built from, besides the searchable ones: a changed title
     * changes the row.
     *
     * @return list<string>
     */
    protected function searchableIndexColumns(): array
    {
        return array_values(array_filter([
            property_exists($this, 'searchableTitle') ? $this->searchableTitle : null,
            property_exists($this, 'searchableSubtitle') ? $this->searchableSubtitle : null,
        ]));
    }

    /**
     * The values at a dotted path, through relations and collections: "tasks.title" from a
     * project is every task's title.
     *
     * @param  mixed  $target
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function valuesAt(mixed $target, array $segments): array
    {
        if ($target === null) {
            return [];
        }

        if ($target instanceof Collection) {
            return $target->flatMap(fn ($item) => self::valuesAt($item, $segments))->values()->all();
        }

        if ($segments === []) {
            return is_scalar($target) || $target instanceof \Stringable ? [(string) $target] : [];
        }

        $segment = array_shift($segments);
        $next = $target instanceof Model ? $target->getAttribute($segment) : data_get($target, $segment);

        return self::valuesAt($next, $segments);
    }

    /**
     * Whether the model overrides searchableUrl(), so it needs no route to be in the index.
     * A trait's methods are copied into the class, so the declaring class tells nothing: the
     * file the method is written in does.
     *
     * @return bool
     */
    public static function overridesSearchableUrl(): bool
    {
        return (new \ReflectionMethod(static::class, 'searchableUrl'))->getFileName()
            !== (new \ReflectionClass(HasSearch::class))->getFileName();
    }
}
