<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** A queued job with no tenant logic of its own. */
final class CountVisibleNotes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $lastCount = null;

    public function handle(): void
    {
        self::$lastCount = DB::table('notes')->count();
    }
}
