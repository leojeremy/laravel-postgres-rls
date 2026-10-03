<?php

declare(strict_types=1);

namespace LeoJeremy\PostgresRls\Testing;

use Symfony\Component\Finder\Finder;

/**
 * Finds code that switches tenant context by hand instead of through
 * RlsContext. For an architecture test in the app:
 *
 *     expect((new HandRolledContextScanner)->scan([app_path()]))->toBe([]);
 *
 * Comments are stripped before matching, so a docblock that explains the
 * rule does not trip it.
 */
final class HandRolledContextScanner
{
    public const DEFAULT_PATTERNS = [
        // stancl: tenancy()->initialize($tenant). The `initialized`
        // property read used as a guard does not match.
        '/tenancy\(\)\s*->\s*initialize\s*\(\s*\$/',
        // Raw writes to the session variable.
        '/\bSET\s+(LOCAL\s+|SESSION\s+)?app\.current_tenant_id\b/i',
        '/set_config\s*\(\s*[\'"]app\.current_tenant_id[\'"]/i',
    ];

    /** @param  list<string>  $patterns */
    public function __construct(private readonly array $patterns = self::DEFAULT_PATTERNS) {}

    public function matches(string $source): bool
    {
        $code = self::withoutComments($source);

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $allowedFiles  absolute paths allowed to switch context (the sanctioned wrapper)
     * @return list<string> offending files, absolute paths, sorted
     */
    public function scan(array $directories, array $allowedFiles = []): array
    {
        $directories = array_values(array_filter($directories, 'is_dir'));

        if ($directories === []) {
            return [];
        }

        $allowed = array_map(fn (string $path): string => realpath($path) ?: $path, $allowedFiles);
        $violations = [];

        foreach (Finder::create()->files()->name('*.php')->in($directories) as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();

            if (in_array($path, $allowed, true)) {
                continue;
            }

            if ($this->matches((string) file_get_contents($path))) {
                $violations[] = $path;
            }
        }

        sort($violations);

        return $violations;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (\PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
