![Larasearch](art/banner.png)

# Larasearch

<p align="center">
    <a href="https://github.com/edulazaro/larasearch/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/larasearch/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/larasearch"><img src="https://img.shields.io/packagist/v/edulazaro/larasearch" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/larasearch"><img src="https://img.shields.io/packagist/dt/edulazaro/larasearch" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/larasearch"><img src="https://img.shields.io/packagist/php-v/edulazaro/larasearch" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/larasearch/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/larasearch" alt="License"></a>
</p>

**Text search for Eloquent models on your own database.** No Elasticsearch, no Meilisearch, no service to run. Two uses, one trait:

- **Lists.** Each model keeps a normalized `search_text` column built from the fields you name, and `searchText($term)` filters on it inside any query, next to your own scopes, filters and pagination.
- **A global index**, for command palettes and site-wide search. Models that say how to show themselves keep a row in one `searchables` table, scoped by tenant and filtered, at search time, by what each user may see.

Every word of the term must appear, in any order and in any field. Text is stored and searched the same way: lower case, without accents, with single spaces, so "camion" finds "Camión". LIKE wildcards are escaped, so "50%" means fifty per cent. On MySQL whole words go through a FULLTEXT index.

## Installation

```bash
composer require edulazaro/larasearch
php artisan migrate
```

The migration creates the `searchables` table for the global index. There is no config file.

## Searching a model's lists

Add the column to the model's table:

```php
Schema::table('projects', function (Blueprint $table) {
    $table->searchable();   // search_text, and its FULLTEXT index on MySQL
});
```

Use the trait and name the fields:

```php
use EduLazaro\Larasearch\Concerns\HasSearch;

class Project extends Model
{
    use HasSearch;

    protected array $searchable = ['event_name', 'company', 'client_name', 'email'];
}
```

Fill it for the rows that already exist:

```bash
php artisan search:reindex "App\Models\Project"
```

And search:

```php
Project::searchText('acme sitges')->paginate();

Project::visibleTo($user)
    ->searchText($request->q)
    ->where('status', 'confirmed')
    ->latest()
    ->paginate(25);
```

`searchText()` is a scope: it adds one condition on the model's own table and combines with anything else in the query. An empty or blank term leaves the query alone, so a filter can always pass it on.

The column is written when the model is saved, in the same write, and only when one of the fields changed: saving a project whose status alone changed costs nothing extra.

A table without the column fails clearly, naming the migration to add, instead of an SQL error in the middle of a list.

### Fields of related models

A field with a dot is read through a relation:

```php
protected array $searchable = ['name', 'client', 'tasks.title', 'owner.name'];
```

The project's text then holds its tasks' titles and its owner's name, and searching "contrato marco" finds the project whose task is called that. When a task is saved or deleted, or the owner renamed, the project is indexed again. Searchable models in `app/Models` are found on their own, so this works even when the request or the queued job that saves the task never touched a project.

For long lists of children, search the children themselves instead: give `Task` its own `HasSearch` and column, and let its visibility follow its parent's (below).

### A text that is not a list of fields

Override `searchableText()`:

```php
public function searchableText(): string
{
    return Text::normalize($this->reference.' '.$this->court->name);
}
```

## The global index (command palettes)

A model joins the global index when it says how to show itself:

```php
class Project extends Model
{
    use HasSearch;

    protected array $searchable = ['event_name', 'company', 'client_name', 'email'];

    protected string $searchableTitle = 'event_name';
    protected ?string $searchableSubtitle = 'company';
    protected string $searchableRoute = 'projects.show';

    public static function searchableFor(User $user): Builder
    {
        return static::visibleTo($user);
    }
}
```

Its row in `searchables` holds the title, the subtitle and the text, so a palette paints results without loading the records. The link is built when read, from the route and the key, so a changed route leaves nothing stale. Override `searchableTitle()` or `searchableUrl()` for computed ones.

Searching:

```php
use EduLazaro\Larasearch\Models\Index;

$results = Index::searchText('acme')
    ->in($organization)
    ->for($user)
    ->limit(10)
    ->get();

foreach ($results as $result) {
    $result->title;          // "Congreso Anual"
    $result->subtitle;       // "Acme Events"
    $result->url();          // route('projects.show', 24)
    $result->searchable;     // the record, if you need it
}
```

### Who sees what

`for($user)` keeps, model by model, the rows whose record is in that model's `searchableFor($user)`. Write it with the same scope your lists use, so the palette sees exactly what the screens see:

```php
// A project manager sees the projects assigned to them.
public static function searchableFor(User $user): Builder
{
    return static::visibleTo($user);
}

// A task is seen by whoever sees its project.
public static function searchableFor(User $user): Builder
{
    return static::whereIn('project_id', Project::visibleTo($user)->select('id'));
}

// Public on purpose.
public static function searchableFor(?User $user): Builder
{
    return static::query();
}
```

Visibility is never stored in the index: it is asked at search time. Reassigning a project to someone else removes it from the previous person's palette at once, with no reindex.

A model in the index without `searchableFor()` fails loudly rather than showing everything to everyone.

### Tenants

For multi-tenant applications each row carries its tenant. Say how to find it once, usually in a service provider:

```php
use EduLazaro\Larasearch\Larasearch;

Larasearch::resolveScopeUsing(fn (Model $model) => $model->organization);
```

or per model, with `searchableScope()`. Then `in()` keeps one tenant's rows, several tenants' (`in($agency->offices)`), or the rows with no tenant (`in(null)`). It is explicit on purpose: which tenant a query reads should be visible in the code.

## Keeping it up to date

Saving, deleting and restoring a model keep its column and its index row up to date, and so do changes to the related models its dotted fields read.

Changes made around Eloquent fire no event: a query builder `update()`, an import, raw SQL, a pivot `attach()`. After them:

```bash
php artisan search:reindex                      # every searchable model in app/Models
php artisan search:reindex "App\Models\Project" # one
```

It writes every record again in batches and removes index rows whose record no longer exists.

## How the words are matched

| | MySQL | SQLite, PostgreSQL |
|---|---|---|
| Words of 3+ letters and digits | FULLTEXT, from the start of a word ("sitg" finds "sitges") | LIKE, anywhere |
| Shorter words, emails, InnoDB stopwords | LIKE, anywhere | LIKE, anywhere |

Short words go through LIKE because MySQL's FULLTEXT index does not store them, and a required word it cannot find would turn every search into "nothing found": "forza horizon 6" still finds Forza Horizon 6.

Numbers go through LIKE too, whatever their length: the index finds a word only from its start, and a piece of a phone or a reference is typed from anywhere in it, so "450" finds "654450123". LIKE reads every row it is given; with other words in the term the index narrows the rows first. On a table of hundreds of thousands of rows, a term made only of numbers is a full scan: that is the point to move to a search engine.

## Requirements

PHP 8.2+ and Laravel 12+. Tested on MySQL 8 (the FULLTEXT path) and SQLite. MariaDB and PostgreSQL use the LIKE path, which those tests cover, but the package's CI does not run on them yet.

## Testing

```bash
composer test
LARASEARCH_DB=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=larasearch DB_USERNAME=root DB_PASSWORD=secret composer test
```

## Sponsors

Larasearch is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Larasearch is open-sourced software licensed under the [MIT license](LICENSE.md).
