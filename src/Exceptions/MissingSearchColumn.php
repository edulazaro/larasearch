<?php

namespace EduLazaro\Larasearch\Exceptions;

use LogicException;

/**
 * A model uses HasSearch but its table has no search_text column.
 */
class MissingSearchColumn extends LogicException
{
    /**
     * @param  string  $model
     * @param  string  $table
     * @return self
     */
    public static function on(string $model, string $table): self
    {
        return new self("{$model} uses HasSearch but its table \"{$table}\" has no search_text column. Add \$table->searchable() to a migration of \"{$table}\" and run php artisan search:reindex \"{$model}\".");
    }
}
