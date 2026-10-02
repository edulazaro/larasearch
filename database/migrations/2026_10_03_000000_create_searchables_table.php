<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The global index (`EduLazaro\Larasearch\Models\Index`): one row per record shown in a
     * command palette. Ids as text, so numeric, UUID and ULID keys share it; the tenant as
     * laraterms keeps it, '' and '' for none. No URL: it is built when read, from the
     * model's route, so a changed route leaves nothing stale here.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('searchables', function (Blueprint $table) {
            $table->id();
            $table->string('searchable_type');
            $table->string('searchable_id', 36);
            $table->string('scope_type')->default('');
            $table->string('scope_id', 36)->default('');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('search_text');
            $table->timestamps();

            $table->unique(['searchable_type', 'searchable_id']);
            $table->index(['scope_type', 'scope_id', 'searchable_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('searchables', fn (Blueprint $table) => $table->fullText('search_text'));
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('searchables');
    }
};
