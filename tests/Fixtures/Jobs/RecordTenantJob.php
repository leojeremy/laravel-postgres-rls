<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\Queue\RunAsDispatchingTenant;

/** Records the tenant the database session runs as while the job runs. */
final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<string> */
    public static array $seen = [];

    public function __construct(
        public bool $withMiddleware = false,
        public bool $fail = false,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->withMiddleware ? [new RunAsDispatchingTenant] : [];
    }

    public function handle(): void
    {
        self::$seen[] = (string) DB::scalar("SELECT current_setting('app.current_tenant_id', true)");

        if ($this->fail) {
            throw new \RuntimeException('job failed');
        }
    }
}
