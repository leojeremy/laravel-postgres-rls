<?php

declare(strict_types=1);

use LeoJeremy\PostgresRls\Audit\ModelTableResolver;

it('reads the class name from the file, not from its path', function (): void {
    $source = <<<'PHP'
        <?php
        namespace Domain\Billing\Entities;

        use Foo\Bar;

        /** Mentions class Fake in a comment. */
        final class Invoice extends Bar
        {
            public function x(): string { return Bar::class; }
        }
        PHP;

    expect(ModelTableResolver::classIn($source))->toBe('Domain\Billing\Entities\Invoice');
});

it('returns null for files without a class', function (): void {
    expect(ModelTableResolver::classIn("<?php\n\nreturn ['a' => Foo::class];\n"))->toBeNull();
});

it('finds tables of models that use a configured tenant trait', function (): void {
    $resolver = new ModelTableResolver(
        [dirname(__DIR__).'/Fixtures/Models'],
        ['LeoJeremy\PostgresRls\Tests\Fixtures\Models\StampsTenant'],
    );

    expect($resolver->stampedTables())->toBe(['invoices']);
});
