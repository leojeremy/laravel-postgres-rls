<?php

declare(strict_types=1);
use LeoJeremy\PostgresRls\Eloquent\StampsTenantId;

return [

    /*
    | Connection that carries the tenant session variable. null = default.
    | It must connect as a role with NOSUPERUSER and NOBYPASSRLS, or no
    | policy applies (php artisan rls:audit checks this).
    */
    'connection' => env('RLS_CONNECTION'),

    /*
    | session:     plain SET, reset by RlsContext after every block. For
    |              direct connections or a pooler in session mode.
    | transaction: SET LOCAL inside a transaction RlsContext opens. For
    |              PgBouncer in transaction pooling mode. See the README.
    */
    'mode' => env('RLS_MODE', 'session'),

    /*
    | SQL type of tenants' primary key and every tenant_id column:
    | bigint, uuid or text. Read by the setup migration, so change it
    | before migrating.
    */
    'key_type' => env('RLS_KEY_TYPE', 'bigint'),

    /*
    | Carry the dispatching tenant into queued jobs (session mode does it
    | automatically; transaction mode needs the RunAsDispatchingTenant job
    | middleware). Set to false if stancl's QueueTenancyBootstrapper
    | already does this for you.
    */
    'queue' => [
        'propagate' => env('RLS_QUEUE_PROPAGATE', true),
    ],

    'audit' => [

        // Schema whose tables rls:audit inspects.
        'schema' => 'public',

        // Tables with a tenant_id column that are deliberately not isolated.
        'ignored_tables' => [],

        /*
        | Directories scanned for Eloquent models that stamp tenant_id in PHP
        | (via one of the traits below). Their tables do not need a
        | DEFAULT current_tenant_id(). Every other NOT NULL tenant_id column
        | does, or pivot attach() and raw inserts fail the policy.
        */
        'model_paths' => [
            app_path('Models'),
        ],

        'tenant_traits' => [
            StampsTenantId::class,
            'Stancl\Tenancy\Database\Concerns\BelongsToTenant',
        ],
    ],

];
