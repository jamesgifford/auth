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
    public function test_dev_data_config_reads_the_prefixed_password_env_var(): void
    {
        $key = 'JAMESGIFFORD_AUTH_DEV_PASSWORD';

        $_SERVER[$key] = 'secret-from-env';
        try {
            $config = require $this->packageRoot().'/config/dev-data.php';
            $this->assertSame('secret-from-env', $config['password']);
        } finally {
            unset($_SERVER[$key]);
        }

        // Falls back to 'password' when the env var is absent.
        $config = require $this->packageRoot().'/config/dev-data.php';
        $this->assertSame('password', $config['password']);
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

        foreach (['src', 'database', 'routes'] as $dir) {
            $path = $this->packageRoot().DIRECTORY_SEPARATOR.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach ($this->envCallLines((string) file_get_contents($file->getPathname())) as $line) {
                    $offenders[] = str_replace($this->packageRoot().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.$line;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env() must only be called in config files. Read the corresponding config() key instead. Found:\n".implode("\n", $offenders),
        );
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
