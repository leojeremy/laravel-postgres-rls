<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LeoJeremy\PostgresRls\RlsContext;
use Workbench\App\Jobs\CountVisibleNotes;
use Workbench\App\Models\Note;
use Workbench\App\Models\Tag;
use Workbench\App\Models\Tenant;

/**
 * Walks through what the package does against a real database. Each step
 * checks its own outcome, so the command exits non-zero if the database
 * did not behave as described.
 */
final class DemoCommand extends Command
{
    protected $signature = 'rls:demo';

    protected $description = 'Seed two tenants and show row-level security doing the isolation.';

    private bool $failed = false;

    public function handle(RlsContext $rls): int
    {
        $this->components->info('1. Fresh schema (as the app role) and two tenants');
        $this->call('migrate:fresh', ['--force' => true]);

        $alpha = Tenant::create(['name' => 'Alpha']);
        $bravo = Tenant::create(['name' => 'Bravo']);

        $rls->within($alpha->id, function (): void {
            $note = Note::create(['title' => 'Alpha roadmap']);
            Note::create(['title' => 'Alpha hiring plan']);
            // A pivot row with no model of its own: tenant_id comes from the
            // column's DEFAULT current_tenant_id().
            $note->tags()->attach(Tag::create(['name' => 'planning']));
        });
        $rls->within($bravo->id, fn () => Note::create(['title' => 'Bravo pricing']));

        $this->line("   Tenants: Alpha (id {$alpha->id}), Bravo (id {$bravo->id}). 3 notes in total.");

        $this->components->info('2. A raw query with no WHERE clause, as Alpha');
        $titles = $rls->within($alpha->id, fn () => DB::table('notes')->orderBy('title')->pluck('title')->all());
        $this->line('   DB::table(\'notes\')->get() returned: '.implode(', ', $titles));
        $this->check($titles === ['Alpha hiring plan', 'Alpha roadmap'], 'Only Alpha\'s 2 notes are visible.');

        $this->components->info('3. The same query with no tenant set');
        $count = DB::table('notes')->count();
        $this->line("   DB::table('notes')->count() = {$count}");
        $this->check($count === 0, 'No tenant in scope means no rows: it fails closed.');

        $this->components->info('4. A forged insert: Alpha writes a row stamped with Bravo\'s id');
        $error = $rls->within($alpha->id, function () use ($bravo): ?QueryException {
            try {
                DB::table('notes')->insert(['tenant_id' => $bravo->id, 'title' => 'planted']);
            } catch (QueryException $e) {
                return $e;
            }

            return null;
        });
        $state = $error?->errorInfo[0] ?? 'none';
        $this->line('   '.($error ? strtok((string) $error->getPrevious()?->getMessage(), "\n") : 'The insert was accepted.'));
        $this->check($state === '42501', 'Rejected by the policy\'s WITH CHECK.');

        $this->components->info('5. A job queued as Alpha, run later by a worker with no tenant set');
        config(['queue.default' => 'database', 'queue.failed.driver' => 'null']);
        $rls->within($alpha->id, fn () => CountVisibleNotes::dispatch());
        $this->call('queue:work', ['connection' => 'database', '--once' => true]);
        $this->line('   The job counted '.CountVisibleNotes::$lastCount.' note(s).');
        $this->check(CountVisibleNotes::$lastCount === 2, 'The tenant travelled in the job payload.');

        $this->components->info('6. rls:audit as the app role');
        $this->check($this->call('rls:audit') === self::SUCCESS, 'The audit passes.');

        $this->components->info('7. One extra PERMISSIVE policy, added "for reporting"');
        DB::statement('CREATE POLICY reports_read_all ON notes FOR SELECT USING (true)');
        $leaked = $rls->within($alpha->id, fn () => DB::table('notes')->count());
        $this->line("   Alpha now sees {$leaked} notes: Postgres ORs permissive policies together.");
        $this->check(
            $leaked === 3 && $this->call('rls:audit') === self::FAILURE,
            'The leak is real, and the audit names the policy that causes it.',
        );
        DB::statement('DROP POLICY reports_read_all ON notes');

        $this->components->info('8. rls:audit as a superuser, same database, same tables');
        $this->check(
            $this->call('rls:audit', ['--database' => 'pgsql_superuser']) === self::FAILURE,
            'The audit fails: a superuser skips every policy, FORCE or not.',
        );

        if ($this->failed) {
            $this->components->error('The database did not behave as described above.');

            return self::FAILURE;
        }

        $this->components->info('Demo complete.');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $claim): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(($ok ? '   <fg=green>✓</> ' : '   <fg=red>✗ NOT TRUE:</> ').$claim);
        $this->newLine();
    }
}
