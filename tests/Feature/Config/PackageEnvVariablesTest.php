<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Config;

use FilesystemIterator;
use JamesGifford\Auth\Database\IdOffsetManager;
use JamesGifford\Auth\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The package's env variables all use the JAMESGIFFORD_AUTH_ prefix, and env()
 * is read ONLY in config files (Laravel best practice — and avoids the
 * config-cache pitfall where a direct env() returns null). These tests pin both
 * by reading the real config files and scanning the package's PHP source.
 */
class PackageEnvVariablesTest extends TestCase
{
    /**
     * A COMPLETE prefixed env var name. The trailing segment must be
     * alphanumeric, so IdOffsetManager's bare 'JAMESGIFFORD_AUTH_' fragment —
     * which it concatenates with a table name at runtime — is not mistaken for
     * a name in its own right.
     */
    private const ENV_NAME_PATTERN = 'JAMESGIFFORD_AUTH_[A-Z0-9]+(?:_[A-Z0-9]+)*';

    public function test_dev_data_config_reads_the_prefixed_password_env_var(): void
    {
        $key = 'JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD';

        $_SERVER[$key] = 'secret-from-env';
        try {
            $config = require $this->packageRoot().'/config/auth-dev.php';
            $this->assertSame('secret-from-env', $config['users_password']);
        } finally {
            unset($_SERVER[$key]);
        }

        // Falls back to 'password' when the env var is absent.
        $config = require $this->packageRoot().'/config/auth-dev.php';
        $this->assertSame('password', $config['users_password']);
    }

    public function test_auth_config_reads_the_prefixed_id_offset_env_vars(): void
    {
        $usersKey = IdOffsetManager::envKeyFor('users');       // JAMESGIFFORD_AUTH_USERS_ID_OFFSET
        $accountsKey = IdOffsetManager::envKeyFor('accounts'); // JAMESGIFFORD_AUTH_ACCOUNTS_ID_OFFSET

        $_SERVER[$usersKey] = '11';
        $_SERVER[$accountsKey] = '1001';
        try {
            $config = require $this->packageRoot().'/config/auth.php';
            $this->assertSame('11', $config['id_offsets']['users']);
            $this->assertSame('1001', $config['id_offsets']['accounts']);
        } finally {
            unset($_SERVER[$usersKey], $_SERVER[$accountsKey]);
        }

        // Null (no offset) when the env vars are absent.
        $config = require $this->packageRoot().'/config/auth.php';
        $this->assertNull($config['id_offsets']['users']);
        $this->assertNull($config['id_offsets']['accounts']);
    }

    public function test_env_is_called_only_in_config_files(): void
    {
        $offenders = [];

        foreach ($this->packageFiles(['src', 'database', 'routes', 'resources'], ['php']) as $file) {
            foreach ($this->envCallLines((string) file_get_contents($file)) as $line) {
                $offenders[] = $this->relative($file).':'.$line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env() must only be called in config files. Read the corresponding config() key instead. Found:\n".implode("\n", $offenders),
        );
    }

    /**
     * Every JAMESGIFFORD_AUTH_* name written into source or documentation must
     * be one a config file actually reads.
     *
     * Config is the authority precisely because of the test above: env() is
     * called nowhere else, so a name absent from every config file reads
     * nothing at runtime.
     *
     * This closes a hole no behavior test can reach. A command that PRINTS a
     * variable name, plus a test asserting that same literal, agree with each
     * other while both disagree with config — the suite stays green while setup
     * instructs the developer to set a variable nothing reads.
     */
    public function test_env_var_names_in_source_and_docs_are_read_by_a_config_file(): void
    {
        $known = $this->envNamesReadByConfig();

        $this->assertNotEmpty($known, 'Sanity: config must read at least one prefixed env var.');

        $offenders = [];

        foreach ($this->filesThatMayNameEnvVars() as $file) {
            foreach ($this->envNamesIn((string) file_get_contents($file)) as $name) {
                if (! in_array($name, $known, true)) {
                    $offenders[] = $this->relative($file).' → '.$name;
                }
            }
        }

        $offenders = array_values(array_unique($offenders));

        $this->assertSame([], $offenders, sprintf(
            "Every %s* name in source or docs must be read by a config file.\nRead by config: %s\nNot read by any config file:\n%s",
            'JAMESGIFFORD_AUTH_',
            implode(', ', $known),
            implode("\n", $offenders),
        ));
    }

    /**
     * The names the config files actually read via env() — the source of truth.
     *
     * @return list<string>
     */
    private function envNamesReadByConfig(): array
    {
        $names = [];

        foreach ((array) glob($this->packageRoot().'/config/*.php') as $file) {
            preg_match_all(
                '/env\(\s*[\'"]('.self::ENV_NAME_PATTERN.')[\'"]/',
                (string) file_get_contents((string) $file),
                $matches,
            );
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function envNamesIn(string $contents): array
    {
        preg_match_all('/'.self::ENV_NAME_PATTERN.'/', $contents, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Source and documentation that may NAME an env var.
     *
     * CHANGELOG.md is deliberately absent: it records names as they were at past
     * releases, so a renamed variable SHOULD survive there. tests/ is absent
     * too — scanning src/ already closes the drift hole, and including tests
     * would break the moment a negative test uses a deliberately bogus name.
     *
     * @return list<string>
     */
    private function filesThatMayNameEnvVars(): array
    {
        return array_values(array_filter(
            array_merge(
                [$this->packageRoot().DIRECTORY_SEPARATOR.'README.md'],
                $this->packageFiles(['src', 'config', 'database', 'routes', 'resources'], ['php', 'md']),
            ),
            'is_file',
        ));
    }

    /**
     * Absolute paths of files with any of $extensions beneath the given package
     * directories. Both scanning tests in this class walk the tree, so the walk
     * lives here once rather than being copied per test.
     *
     * @param  list<string>  $dirs  package-root-relative
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function packageFiles(array $dirs, array $extensions): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $path = $this->packageRoot().DIRECTORY_SEPARATOR.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * A package-root-relative path, for readable failure messages.
     */
    private function relative(string $path): string
    {
        return str_replace($this->packageRoot().DIRECTORY_SEPARATOR, '', $path);
    }

    /**
     * Line numbers where env() is invoked as a function in real code, ignoring
     * comments, string literals, and method calls (->environment(), getenv()).
     *
     * @return list<int>
     */
    private function envCallLines(string $contents): array
    {
        $lines = [];
        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'env') {
                continue;
            }

            // The next significant token must be an opening parenthesis.
            $next = $this->significantNeighbour($tokens, $i, 1);
            if ($next !== '(') {
                continue;
            }

            // The previous significant token must NOT make this a method
            // call/definition ($x->env(), Foo::env(), function env()).
            $prev = $this->significantNeighbour($tokens, $i, -1);
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            $lines[] = $token[2];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significantNeighbour(array $tokens, int $index, int $direction): array|string|null
    {
        $i = $index + $direction;
        while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i += $direction;
        }

        return $tokens[$i] ?? null;
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
