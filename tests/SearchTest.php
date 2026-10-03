<?php

namespace EduLazaro\Larasearch\Tests;

use EduLazaro\Larasearch\Exceptions\MissingSearchColumn;
use EduLazaro\Larasearch\Exceptions\MissingVisibility;
use EduLazaro\Larasearch\Models\Index;
use EduLazaro\Larasearch\Support\Matcher;
use EduLazaro\Larasearch\Support\Relations;
use EduLazaro\Larasearch\Support\Text;
use EduLazaro\Larasearch\Tests\Fixtures\Article;
use EduLazaro\Larasearch\Tests\Fixtures\Contact;
use EduLazaro\Larasearch\Tests\Fixtures\Organization;
use EduLazaro\Larasearch\Tests\Fixtures\Project;
use EduLazaro\Larasearch\Tests\Fixtures\Task;
use EduLazaro\Larasearch\Tests\Fixtures\Untracked;
use EduLazaro\Larasearch\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SearchTest extends TestCase
{
    public function test_text_is_normalized_the_same_when_stored_and_searched(): void
    {
        $this->assertSame('hotel arts, barcelona camion', Text::normalize("  Hotel  Arts,\tBARCELONA  Camión "));
        $this->assertSame(['acme', 'sitges'], Text::words(' Acme  SITGES acme '));
        $this->assertSame([], Text::words('   '));
        $this->assertSame('', Text::normalize(null));
    }

    public function test_saving_keeps_the_text_of_its_fields(): void
    {
        $owner = User::create(['name' => 'Laura Gómez']);
        $project = Project::create(['name' => 'Congreso Anual', 'company' => 'Acme Events', 'email' => 'info@acme.test', 'owner_id' => $owner->id]);

        $this->assertSame('congreso anual acme events info@acme.test laura gomez', $project->fresh()->search_text);
    }

    public function test_a_change_outside_the_fields_does_not_rewrite_the_text(): void
    {
        $project = Project::create(['name' => 'Congreso']);

        // Changed behind Eloquent's back: only a save of a searchable field writes it again.
        DB::table('projects')->where('id', $project->id)->update(['search_text' => 'stale']);

        $project->fresh()->update(['status' => 'closed']);
        $this->assertSame('stale', $project->fresh()->search_text);

        $project->fresh()->update(['name' => 'Gala']);
        $this->assertSame('gala', $project->fresh()->search_text);
    }

    public function test_what_an_observer_fills_in_on_saving_is_in_the_text(): void
    {
        $lead = \EduLazaro\Larasearch\Tests\Fixtures\Lead::create(['name' => 'Laura']);

        $this->assertSame('laura kept', $lead->fresh()->search_text);
    }

    public function test_every_word_in_any_order_and_any_field(): void
    {
        Project::create(['name' => 'Congreso Anual', 'company' => 'Acme Events']);
        Project::create(['name' => 'Gala', 'company' => 'Acme']);
        Project::create(['name' => 'Camión de bomberos', 'company' => 'Otra']);

        $this->assertSame(['Congreso Anual'], Project::searchText('ANUAL acme')->pluck('name')->all());
        $this->assertSame(2, Project::searchText('acme')->count());
        $this->assertSame(['Camión de bomberos'], Project::searchText('camion')->pluck('name')->all());
        $this->assertSame(3, Project::searchText('   ')->count());
        $this->assertSame(3, Project::searchText(null)->count());
        $this->assertSame(0, Project::searchText('acme nothing')->count());
    }

    public function test_wildcards_are_taken_literally(): void
    {
        Project::create(['name' => 'Descuento 50%']);
        Project::create(['name' => 'Descuento 500']);
        Project::create(['name' => 'a_b']);
        Project::create(['name' => 'axb']);

        $this->assertSame(['Descuento 50%'], Project::searchText('50%')->pluck('name')->all());
        $this->assertSame(['a_b'], Project::searchText('a_b')->pluck('name')->all());
    }

    public function test_it_combines_with_the_rest_of_a_query(): void
    {
        $mine = User::create(['name' => 'Ana']);
        Project::create(['name' => 'Acme mine', 'owner_id' => $mine->id, 'status' => 'open']);
        Project::create(['name' => 'Acme closed', 'owner_id' => $mine->id, 'status' => 'closed']);
        Project::create(['name' => 'Acme theirs', 'owner_id' => User::create(['name' => 'Bruno'])->id]);

        $found = Project::searchableFor($mine)->searchText('acme')->where('status', 'open')->pluck('name')->all();

        $this->assertSame(['Acme mine'], $found);
    }

    public function test_a_related_record_keeps_its_parent_up_to_date(): void
    {
        $owner = User::create(['name' => 'Laura']);
        $project = Project::create(['name' => 'Gala', 'owner_id' => $owner->id]);

        $task = Task::create(['project_id' => $project->id, 'title' => 'Contrato marco']);
        $this->assertSame(1, Project::searchText('contrato marco')->count());

        $task->update(['title' => 'Catering']);
        $this->assertSame(0, Project::searchText('contrato')->count());
        $this->assertSame(1, Project::searchText('catering')->count());

        $owner->update(['name' => 'Marta']);
        $this->assertSame(1, Project::searchText('marta gala')->count());

        $task->delete();
        $this->assertSame(0, Project::searchText('catering')->count());
    }

    public function test_models_in_a_directory_are_found_without_being_used_first(): void
    {
        Relations::flush();
        Relations::discover(__DIR__.'/Fixtures', 'EduLazaro\\Larasearch\\Tests\\Fixtures\\');

        $this->assertEqualsCanonicalizing([Project::class, Contact::class, Article::class, Untracked::class, \EduLazaro\Larasearch\Tests\Fixtures\Lead::class], Relations::models());
    }

    public function test_a_table_without_the_column_fails_clearly(): void
    {
        $this->expectException(MissingSearchColumn::class);
        $this->expectExceptionMessage('$table->searchable()');

        Untracked::create(['name' => 'x']);
    }

    public function test_the_index_holds_what_shows_itself_in_a_palette(): void
    {
        $organization = Organization::create(['name' => 'CREA']);
        $project = Project::create(['name' => 'Congreso', 'company' => 'Acme', 'organization_id' => $organization->id]);
        Contact::create(['name' => 'Acme contact']);

        $row = Index::sole();
        $this->assertSame([$project->getMorphClass(), (string) $project->id, $organization->getMorphClass(), (string) $organization->id, 'Congreso', 'Acme'],
            [$row->searchable_type, $row->searchable_id, $row->scope_type, $row->scope_id, $row->title, $row->subtitle]);
        $this->assertSame(route('projects.show', $project->id), $row->url());

        $project->update(['name' => 'Gala']);
        $this->assertSame('Gala', Index::sole()->title);

        $project->delete();
        $this->assertSame(0, Index::count());
        $project->restore();
        $this->assertSame(1, Index::count());
    }

    public function test_the_palette_keeps_a_tenant_and_what_the_user_may_see(): void
    {
        $crea = Organization::create(['name' => 'CREA']);
        $other = Organization::create(['name' => 'Other']);
        $ana = User::create(['name' => 'Ana']);
        $bruno = User::create(['name' => 'Bruno']);

        $mine = Project::create(['name' => 'Acme one', 'owner_id' => $ana->id, 'organization_id' => $crea->id]);
        Project::create(['name' => 'Acme two', 'owner_id' => $bruno->id, 'organization_id' => $crea->id]);
        Project::create(['name' => 'Acme three', 'owner_id' => $ana->id, 'organization_id' => $other->id]);
        Article::create(['title' => 'Acme guide']);

        $titles = fn ($query) => $query->orderBy('title')->pluck('title')->all();

        $this->assertSame(['Acme one'], $titles(Index::searchText('acme')->in($crea)->for($ana)));
        $this->assertSame(['Acme guide'], $titles(Index::searchText('acme')->in(null)->for($ana)));
        $this->assertSame(['Acme guide', 'Acme one', 'Acme three'], $titles(Index::searchText('acme')->in([$crea, $other, null])->for($ana)));

        // Reassigned: gone from the palette at once, with no reindex.
        $mine->newQuery()->whereKey($mine->id)->toBase()->update(['owner_id' => $bruno->id]);
        $this->assertSame([], $titles(Index::searchText('acme')->in($crea)->for($ana)));
    }

    public function test_a_model_in_the_index_must_say_who_sees_it(): void
    {
        $nobody = new class extends Model
        {
            use \EduLazaro\Larasearch\Concerns\HasSearch;

            protected $table = 'contacts';

            protected $guarded = [];

            protected array $searchable = ['name'];

            protected string $searchableTitle = 'name';

            protected string $searchableRoute = 'projects.show';
        };

        $nobody->newInstance(['name' => 'Acme'])->save();

        $this->expectException(MissingVisibility::class);

        Index::searchText('acme')->for(User::create(['name' => 'Ana']))->get();
    }

    public function test_reindex_rebuilds_after_changes_around_eloquent(): void
    {
        $project = Project::create(['name' => 'Congreso']);
        DB::table('projects')->where('id', $project->id)->update(['name' => 'Gala', 'search_text' => null]);
        DB::table('searchables')->insert(['searchable_type' => $project->getMorphClass(), 'searchable_id' => '999', 'title' => 'Gone', 'search_text' => 'gone', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('search:reindex', ['models' => [Project::class]])->assertSuccessful();

        $this->assertSame('gala', $project->fresh()->search_text);
        $this->assertSame(['Gala'], Index::pluck('title')->all());
    }

    public function test_mysql_uses_the_fulltext_index_for_whole_words(): void
    {
        $this->assertTrue(Matcher::indexable('sitges'));
        $this->assertFalse(Matcher::indexable('de'));
        $this->assertFalse(Matcher::indexable('the'));
        $this->assertFalse(Matcher::indexable('info@acme'));
        $this->assertFalse(Matcher::indexable('600111222'));
        $this->assertTrue(Matcher::indexable('horizon6'));

        if (! $this->onMysql()) {
            $this->markTestSkipped('FULLTEXT runs on MySQL (LARASEARCH_DB=mysql).');
        }

        Project::create(['name' => 'Fiesta en Sitges', 'email' => 'info@acme.test']);
        Project::create(['name' => 'Forza Horizon 6']);
        Project::create(['name' => 'Llamar', 'company' => '654450123']);

        $this->assertSame(1, Project::searchText('sitg')->count());
        $this->assertSame(1, Project::searchText('fiesta en')->count());
        $this->assertSame(1, Project::searchText('forza horizon 6')->count());
        $this->assertSame(1, Project::searchText('info@acme.test')->count());
        // A number from its middle, which the index alone cannot find.
        $this->assertSame(1, Project::searchText('450')->count());
        $this->assertSame(1, Project::searchText('llamar 0123')->count());
        $this->assertStringContainsString('match(', Project::searchText('sitges')->toSql());
    }
}
