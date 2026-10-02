<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Model;

/** Uses HasSearch on a table without the column. */
class Untracked extends Model
{
    use HasSearch;

    protected $table = 'untracked';

    protected $guarded = [];

    protected array $searchable = ['name'];
}
