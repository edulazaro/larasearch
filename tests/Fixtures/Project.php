<?php

namespace EduLazaro\Larasearch\Tests\Fixtures;

use EduLazaro\Larasearch\Concerns\HasSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** In its list and in the palette, with fields of its tasks and its owner. */
class Project extends Model
{
    use HasSearch, SoftDeletes;

    protected $guarded = [];

    protected array $searchable = ['name', 'company', 'email', 'tasks.title', 'owner.name'];

    protected string $searchableTitle = 'name';

    protected ?string $searchableSubtitle = 'company';

    protected string $searchableRoute = 'projects.show';

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function searchableScope(): ?Model
    {
        return $this->organization;
    }

    /** An owner sees their projects. */
    public static function searchableFor(User $user): Builder
    {
        return static::where('owner_id', $user->id);
    }
}
