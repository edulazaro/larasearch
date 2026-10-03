<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

/** A field filled in by an observer on saving. */
#[ObservedBy(LeadObserver::class)]
class Lead extends Model
{
    use HasSearch;

    protected $table = 'contacts';

    protected $guarded = [];

    protected array $searchable = ['name'];
}
