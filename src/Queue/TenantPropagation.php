<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use LeoJeremy\PostgresRls\KeyType;
use LeoJeremy\PostgresRls\Mode;
use LeoJeremy\PostgresRls\RlsContext;
use LeoJeremy\PostgresRls\TenantSession;

/**
 * Carries the dispatching tenant to the worker that runs the job.
 *
 * On dispatch, the tenant in scope (or null) is written into the job
 * payload. On the worker, session mode sets it before the job and puts
 * back whatever was there afterwards, on success and on failure, so a
 * worker that runs jobs for many tenants on one connection never hands a
 * tenant to the next job. A job dispatched with no tenant runs with none.
 *
 * Transaction mode cannot be served from queue events (there is no
 * transaction around the job to SET LOCAL in): give the job the
 * RunAsDispatchingTenant middleware instead.
 *
 * Apps on stancl/tenancy with its QueueTenancyBootstrapper should set
 * rls.queue.propagate to false and let stancl do this.
 */
final class TenantPropagation
{
    public const PAYLOAD_KEY = 'rls_tenant';

    /** @var list<string|null> what each running job replaced, innermost last (sync jobs nest) */
    private array $stack = [];

    public function __construct(
        private readonly TenantSession $session,
        private readonly KeyType $keyType,
        private readonly Mode $mode,
    ) {}

    /** @return array<string, int|string|null> merged into every job payload */
    public static function payload(): array
    {
        return [self::PAYLOAD_KEY => app(RlsContext::class)->currentKey()];
    }

    public static function tenantOf(?Job $job): int|string|null
    {
        $tenant = $job?->payload()[self::PAYLOAD_KEY] ?? null;

        return is_int($tenant) || is_string($tenant) ? $tenant : null;
    }

    public function starting(JobProcessing $event): void
    {
        if ($this->mode !== Mode::Session) {
            return;
        }

        $this->stack[] = $this->session->current();

        $tenant = self::tenantOf($event->job);

        $tenant === null
            ? $this->session->clear()
            : $this->session->set($this->keyType->normalise($tenant));
    }

    public function finished(JobProcessed|JobExceptionOccurred $event): void
    {
        if ($this->mode !== Mode::Session || $this->stack === []) {
            return;
        }

        $previous = array_pop($this->stack);

        $previous === null ? $this->session->clear() : $this->session->set($previous);
    }
}
