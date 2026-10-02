<?php

namespace EduLazaro\Larasearch\Console;

use EduLazaro\Larasearch\Concerns\HasSearch;
use EduLazaro\Larasearch\Models\Index;
use EduLazaro\Larasearch\Support\Relations;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes every record's text and index row again: after adding the column to a table that
 * has data, and after changes made around Eloquent (a query builder update, an import, a
 * pivot attach), which fire no event. Rows of records that no longer exist are removed.
 */
class ReindexCommand extends Command
{
    protected $signature = 'search:reindex
        {models?* : Model classes; every searchable model in app/Models when none}
        {--chunk=500 : Records per batch}';

    protected $description = 'Write the search text and the global index again';

    /**
     * @return int
     */
    public function handle(): int
    {
        $models = $this->argument('models') ?: $this->discovered();

        if ($models === []) {
            $this->warn('No model uses HasSearch.');

            return self::SUCCESS;
        }

        foreach ($models as $class) {
            if (! class_exists($class) || ! in_array(HasSearch::class, class_uses_recursive($class), true)) {
                $this->error("{$class} does not use HasSearch.");

                return self::FAILURE;
            }

            $this->reindex($class);
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $class
     * @return void
     */
    private function reindex(string $class): void
    {
        $count = 0;

        // Every record, whatever the application's global scopes (a tenant's, soft deletes):
        // a deleted one keeps its text and leaves the index.
        $class::query()->withoutGlobalScopes()->chunkById((int) $this->option('chunk'), function ($models) use (&$count) {
            foreach ($models as $model) {
                $model->reindexSearch();

                if (method_exists($model, 'trashed') && $model->trashed()) {
                    $model->forgetSearchIndex();
                }

                $count++;
            }
        });

        $model = new $class;
        $orphans = Index::query()
            ->where('searchable_type', $model->getMorphClass())
            ->whereNotIn('searchable_id', $model->newQueryWithoutScopes()->toBase()->selectRaw(
                'cast('.$model->getQualifiedKeyName().' as '.($model->getConnection()->getDriverName() === 'mysql' ? 'char' : 'varchar').')'
            ))
            ->delete();

        $this->info("{$class}: {$count} indexed".($orphans ? ", {$orphans} orphan rows removed" : '').'.');
    }

    /**
     * @return list<class-string<Model>>
     */
    private function discovered(): array
    {
        Relations::discover();

        return Relations::models();
    }
}
