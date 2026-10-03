<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\RlsSchema;

return new class extends Migration
{
    public function up(): void
    {
        RlsSchema::on(DB::connection(config('rls.connection')))
            ->install(KeyType::fromConfig(config('rls.key_type', 'bigint')));
    }

    public function down(): void
    {
        RlsSchema::on(DB::connection(config('rls.connection')))->uninstall();
    }
};
