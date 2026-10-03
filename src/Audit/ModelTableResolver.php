<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Audit;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/**
 * Finds the tables whose tenant_id is stamped in PHP by an Eloquent model
 * using one of the configured traits (stancl's BelongsToTenant by default).
 *
 * Class names are read from each file's own namespace and class
 * declarations, so any directory layout works, not only PSR-4 guesses
 * from the path.
 */
final class ModelTableResolver
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $traits
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $traits,
    ) {}

    /** @return list<string> */
    public function stampedTables(): array
    {
        $directories = array_values(array_filter($this->paths, 'is_dir'));

        if ($directories === [] || $this->traits === []) {
            return [];
        }

        $tables = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories) as $file) {
            $class = self::classIn((string) file_get_contents($file->getPathname()));

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (array_intersect($this->traits, class_uses_recursive($class)) === []) {
                continue;
            }

            /** @var Model $model */
            $model = $reflection->newInstanceWithoutConstructor();
            $tables[] = $model->getTable();
        }

        return array_values(array_unique($tables));
    }

    /** Fully qualified name of the first class declared in $source, if any. */
    public static function classIn(string $source): ?string
    {
        $tokens = \PhpToken::tokenize($source);
        $namespace = '';

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $name = self::nextName($tokens, $i);
                $namespace = $name === null ? '' : $name.'\\';
            }

            if ($token->is(T_CLASS) && ! self::isClassConstant($tokens, $i)) {
                $name = self::nextName($tokens, $i);

                return $name === null ? null : $namespace.$name;
            }
        }

        return null;
    }

    /** @param  list<\PhpToken>  $tokens */
    private static function nextName(array $tokens, int $i): ?string
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if ($tokens[$j]->isIgnorable()) {
                continue;
            }

            return $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$j]->text : null;
        }

        return null;
    }

    /**
     * `Foo::class` and `new class` are not declarations.
     *
     * @param  list<\PhpToken>  $tokens
     */
    private static function isClassConstant(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j]->isIgnorable()) {
                continue;
            }

            return $tokens[$j]->is([T_DOUBLE_COLON, T_NEW]);
        }

        return false;
    }
}
