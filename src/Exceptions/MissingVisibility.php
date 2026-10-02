<?php

namespace EduLazaro\Larasearch\Exceptions;

use LogicException;

/**
 * A model is in the global index but does not say who may see it.
 */
class MissingVisibility extends LogicException
{
    /**
     * @param  string  $model
     * @return self
     */
    public static function on(string $model): self
    {
        return new self("{$model} is in the global search index but has no searchableFor(\$user). Add it, returning the query of what that user may see (static::query() if it is public), so the index never shows what it should not.");
    }
}
