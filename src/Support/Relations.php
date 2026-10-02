<?php

namespace EduLazaro\Larasearch\Support;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

/**
 * Who reaches whom through a dotted field: when a task is saved, which projects hold
 * "tasks.title" in their text and must be indexed again.
 *
 * Fed by every model that uses HasSearch when it boots, and once per process by looking at
 * the application's models: Laravel boots a model only when it is first used, and a request
 * (or a queued job) that saves a task without ever touching a project would otherwise leave
 * the project's text behind.
 */
final class Relations
{
    /**
     * Related class => [[parent class, relation path], …].
     *
     * @var array<class-string<Model>, list<array{0: class-string<Model>, 1: string}>>
     */
    private static array $watchers = [];

    /** @var array<class-string<Model>, true> */
    private static array $registered = [];

    /**
     * Classes noted while booting, read later: a model cannot be built while it boots.
     *
     * @var list<class-string<Model>>
     */
    private static array $pending = [];

    private static bool $discovered = false;

    /**
     * The records that held a model about to be deleted, found while it still exists.
     *
     * @var array<int, list<Model>>
     */
    private static array $holders = [];

    /**
     * Notes what a searchable model reaches through its dotted fields.
     *
     * @param  class-string<Model>  $class
     * @return void
     */
    public static function register(string $class): void
    {
        if (isset(self::$registered[$class])) {
            return;
        }

        self::$registered[$class] = true;
        self::$pending[] = $class;
    }

    /**
     * Reads the relations of the classes noted while they booted.
     *
     * @return void
     */
    private static function resolve(): void
    {
        while ($class = array_shift(self::$pending)) {
            self::watch($class);
        }
    }

    /**
     * @param  class-string<Model>  $class
     * @return void
     */
    private static function watch(string $class): void
    {
        $model = new $class;

        $paths = collect($model->searchableFields())
            ->filter(fn (string $field) => str_contains($field, '.'))
            ->map(fn (string $field) => implode('.', array_slice(explode('.', $field), 0, -1)))
            ->unique();

        foreach ($paths as $path) {
            if ($related = self::relatedAt($model, explode('.', $path))) {
                self::$watchers[$related][] = [$class, $path];
            }
        }
    }

    /**
     * A model was saved or deleted: indexes again whatever reaches it.
     *
     * @param  Model  $changed
     * @return void
     */
    public static function changed(Model $changed): void
    {
        foreach (self::holders($changed) as $model) {
            $model->reindexSearch();
        }
    }

    /**
     * A model is about to be deleted: once it is gone nothing reaches it any more, so the
     * records that hold it are found now and indexed again when it is gone (`deleted()`).
     *
     * @param  Model  $deleting
     * @return void
     */
    public static function deleting(Model $deleting): void
    {
        self::$holders[spl_object_id($deleting)] = self::holders($deleting);
    }

    /**
     * @param  Model  $deleted
     * @return void
     */
    public static function deleted(Model $deleted): void
    {
        $holders = self::$holders[spl_object_id($deleted)] ?? self::holders($deleted);
        unset(self::$holders[spl_object_id($deleted)]);

        foreach ($holders as $model) {
            $model->reindexSearch();
        }
    }

    /**
     * The searchable records that reach this one through a dotted field.
     *
     * @param  Model  $changed
     * @return list<Model>
     */
    private static function holders(Model $changed): array
    {
        self::discover();
        self::resolve();

        $holders = [];

        foreach (self::$watchers[$changed::class] ?? [] as [$parent, $path]) {
            array_push($holders, ...$parent::query()
                ->whereHas($path, fn ($query) => $query->whereKey($changed->getKey()))
                ->get()
                ->all());
        }

        return $holders;
    }

    /**
     * Registers the searchable models found in a directory (app/Models by default), once.
     *
     * @param  string|null  $path
     * @param  string|null  $namespace
     * @return void
     */
    public static function discover(?string $path = null, ?string $namespace = null): void
    {
        if (self::$discovered && $path === null) {
            return;
        }

        self::$discovered = true;
        $path ??= app_path('Models');
        $namespace ??= app()->getNamespace().'Models\\';

        if (! is_dir($path)) {
            return;
        }

        foreach (File::allFiles($path) as $file) {
            $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Model::class) && in_array(HasSearch::class, class_uses_recursive($class), true)) {
                self::register($class);
            }
        }
    }

    /**
     * The searchable models known so far.
     *
     * @return list<class-string<Model>>
     */
    public static function models(): array
    {
        return array_keys(self::$registered);
    }

    /**
     * Forgets everything (for tests).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$watchers = [];
        self::$registered = [];
        self::$pending = [];
        self::$discovered = false;
    }

    /**
     * The model a relation path ends on ("tasks.comments" from a project: Comment).
     *
     * @param  Model  $model
     * @param  list<string>  $segments
     * @return class-string<Model>|null
     */
    private static function relatedAt(Model $model, array $segments): ?string
    {
        foreach ($segments as $segment) {
            if (! method_exists($model, $segment)) {
                return null;
            }

            $model = $model->{$segment}()->getRelated();
        }

        return $model::class;
    }
}
