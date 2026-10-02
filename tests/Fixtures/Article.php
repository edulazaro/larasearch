<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Public in the palette. */
class Article extends Model
{
    use HasSearch;

    protected $guarded = [];

    protected array $searchable = ['title'];

    protected string $searchableTitle = 'title';

    protected string $searchableRoute = 'articles.show';

    public static function searchableFor(mixed $user): Builder
    {
        return static::query();
    }
}
