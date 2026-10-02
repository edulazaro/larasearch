<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Model;

/** Searched in its list only: no title, so not in the palette. */
class Contact extends Model
{
    use HasSearch;

    protected $guarded = [];

    protected array $searchable = ['name'];
}
