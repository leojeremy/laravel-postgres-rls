<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LeoJeremy\PostgresRls\RlsSchema;

return new class extends Migration
{
    public function up(): void
    {
        // Central table: no tenant_id, no policy.
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('title');
            $table->timestamps();
            // Postgres does not index foreign keys on its own, and every
            // policy check filters on tenant_id.
            $table->index('tenant_id');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            // Unique per tenant, not globally: see rls:audit's warning.
            $table->unique(['tenant_id', 'name']);
        });

        // A pivot: written by attach(), never by a model of its own.
        Schema::create('note_tag', function (Blueprint $table): void {
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained();
            $table->primary(['note_id', 'tag_id']);
            $table->index('tenant_id');
        });

        // Laravel's database queue, for the demo's worker step. Central:
        // no tenant_id, the payload carries the tenant instead.
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $rls = RlsSchema::on(DB::connection());

        foreach (['notes', 'tags', 'note_tag'] as $table) {
            $rls->protect($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('note_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('tenants');
    }
};
