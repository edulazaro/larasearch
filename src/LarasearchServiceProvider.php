<?php

namespace EduLazaro\Larasearch;

use EduLazaro\Larasearch\Console\ReindexCommand;
use EduLazaro\Larasearch\Models\Index;
use EduLazaro\Larasearch\Support\Relations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The searchables table, `$table->searchable()` for the models' own column, the reindex
 * command, and the listener that keeps dotted fields up to date.
 */
class LarasearchServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'larasearch-migrations');

            $this->commands([ReindexCommand::class]);
        }

        // The model's own search column, and its FULLTEXT index where MySQL can use one.
        Blueprint::macro('searchable', function () {
            /** @var Blueprint $this */
            $this->text('search_text')->nullable();

            if (DB::connection()->getDriverName() === 'mysql') {
                $this->fullText('search_text');
            }

            return $this;
        });

        // A related record saved or deleted: the records that hold it in their text follow.
        $related = fn (array $payload) => ($payload[0] ?? null) instanceof Model && ! $payload[0] instanceof Index ? $payload[0] : null;

        Event::listen('eloquent.saved: *', function (string $event, array $payload) use ($related) {
            ($model = $related($payload)) && Relations::changed($model);
        });
        Event::listen('eloquent.deleting: *', function (string $event, array $payload) use ($related) {
            ($model = $related($payload)) && Relations::deleting($model);
        });
        Event::listen('eloquent.deleted: *', function (string $event, array $payload) use ($related) {
            ($model = $related($payload)) && Relations::deleted($model);
        });
    }
}
