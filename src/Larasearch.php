<?php

namespace EduLazaro\Larasearch;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The one setting there is: how to find a model's tenant, for the global index. Set once,
 * usually in a service provider; a model can answer for itself with `searchableScope()`.
 *
 *     Larasearch::resolveScopeUsing(fn (Model $model) => $model->organization);
 */
final class Larasearch
{
    /** @var (Closure(Model): ?Model)|null */
    private static ?Closure $scopeResolver = null;

    /**
     * @param  (Closure(Model): ?Model)|null  $resolver
     * @return void
     */
    public static function resolveScopeUsing(?Closure $resolver): void
    {
        self::$scopeResolver = $resolver;
    }

    /**
     * @param  Model  $model
     * @return Model|null
     */
    public static function scopeOf(Model $model): ?Model
    {
        return self::$scopeResolver ? (self::$scopeResolver)($model) : null;
    }

    /**
     * A scope as the two columns it is stored in: '' and '' when there is none.
     *
     * @param  Model|null  $scope
     * @return array{scope_type: string, scope_id: string}
     */
    public static function scopeColumns(?Model $scope): array
    {
        return [
            'scope_type' => $scope ? $scope->getMorphClass() : '',
            'scope_id' => $scope ? (string) $scope->getKey() : '',
        ];
    }
}
