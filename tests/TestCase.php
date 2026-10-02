<?php

namespace EduLazaro\Larasearch\Tests;

use EduLazaro\Larasearch\Larasearch;
use EduLazaro\Larasearch\LarasearchServiceProvider;
use EduLazaro\Larasearch\Support\Relations;
use EduLazaro\Larasearch\Tests\Fixtures\Article;
use EduLazaro\Larasearch\Tests\Fixtures\Contact;
use EduLazaro\Larasearch\Tests\Fixtures\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Boots the package on SQLite in memory, or on MySQL when LARASEARCH_DB=mysql (the CI's
 * second job, for the FULLTEXT path), with the fixtures' tables.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Relations::flush();
        Larasearch::resolveScopeUsing(null);
        foreach ([Project::class, Contact::class, Article::class] as $class) {
            $class::flushSearchColumns();
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->createTables();

        Route::get('projects/{project}', fn () => '')->name('projects.show');
        Route::get('articles/{article}', fn () => '')->name('articles.show');
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LarasearchServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        if (getenv('LARASEARCH_DB') === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'larasearch',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);

            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * @return bool
     */
    protected function onMysql(): bool
    {
        return getenv('LARASEARCH_DB') === 'mysql';
    }

    /**
     * @return void
     */
    private function createTables(): void
    {
        foreach (['tasks', 'projects', 'contacts', 'articles', 'untracked', 'users', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable();
            $table->foreignId('owner_id')->nullable();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('open');
            $table->searchable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->searchable();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->searchable();
            $table->timestamps();
        });

        Schema::create('untracked', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
