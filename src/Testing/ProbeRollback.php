<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Testing;

/** @internal Thrown to roll back a probe INSERT that the database accepted. */
final class ProbeRollback extends \RuntimeException {}
