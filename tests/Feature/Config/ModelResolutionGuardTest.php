<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Config;

use FilesystemIterator;
use JamesGifford\Auth\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the model-resolution contract: package code must resolve the
 * Account, AccountRole, and AccountUser classes through PackageModels, never
 * by calling statics on (or instantiating) the concrete package models. A
 * hardcoded reference silently ignores a consumer's models.* config override
 * — which is exactly the drift that made the override keys dead once before.
 *
 * `X::class` constants remain legal everywhere (imports, config defaults,
 * publisher maps, PackageModels' own fallbacks), as do type-hints and
 * instanceof checks: only static member access and `new` are resolution
 * points.
 */
final class ModelResolutionGuardTest extends TestCase
{
    private const MODEL_IMPORTS = [
        'JamesGifford\Auth\Models\Account' => 'Account',
        'JamesGifford\Auth\Models\AccountRole' => 'AccountRole',
        'JamesGifford\Auth\Models\AccountUser' => 'AccountUser',
    ];

    /**
     * Relative file paths allowed to reference the concrete models. Keep this
     * EMPTY: new code must route through PackageModels. Any addition here is
     * a deliberate, reviewed exception.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [];

    public function test_src_resolves_package_models_only_through_package_models(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder($this->packageRoot().DIRECTORY_SEPARATOR.'src') as $file) {
            $relative = substr($file->getPathname(), strlen($this->packageRoot()) + 1);
            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }

            foreach ($this->hardcodedModelUsages((string) file_get_contents($file->getPathname())) as [$line, $usage]) {
                $offenders[] = "{$relative}:{$line} {$usage}";
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Package models referenced directly instead of through PackageModels:\n%s\n".
            'Route new code through PackageModels so models.* config overrides stay honored.',
            implode("\n", $offenders),
        ));
    }

    /**
     * Static member access (except ::class) on, or `new` instantiation of,
     * one of the three package models — matched against the file's imports
     * of JamesGifford\Auth\Models\* and against qualified name usages.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function hardcodedModelUsages(string $contents): array
    {
        $tokens = array_values(array_filter(
            token_get_all($contents),
            static fn ($token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $importedShortNames = $this->importedModelShortNames($tokens);
        $usages = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            $shortName = $this->packageModelShortName($token, $importedShortNames);
            if ($shortName === null) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $afterNext = $tokens[$i + 2] ?? null;

            // `use JamesGifford\Auth\Models\X;` import statements are legal.
            if (is_array($previous) && $previous[0] === T_USE) {
                continue;
            }

            if (is_array($previous) && $previous[0] === T_NEW) {
                $usages[] = [$token[2], "instantiates {$shortName} directly (new)"];

                continue;
            }

            if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $isClassConstant = is_array($afterNext) && $afterNext[0] === T_CLASS;
                if (! $isClassConstant) {
                    $member = is_array($afterNext) ? $afterNext[1] : '?';
                    $usages[] = [$token[2], "calls {$shortName}::{$member} statically"];
                }
            }
        }

        return $usages;
    }

    /**
     * Short names of the package models this file imports, so a bare
     * `Account::` token can be attributed to the package model rather than
     * an unrelated class of the same name.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<string>
     */
    private function importedModelShortNames(array $tokens): array
    {
        $shortNames = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            if (is_array($next) && $next[0] === T_NAME_QUALIFIED
                && isset(self::MODEL_IMPORTS[$next[1]])) {
                $shortNames[] = self::MODEL_IMPORTS[$next[1]];
            }
        }

        return $shortNames;
    }

    /**
     * The package-model short name this token refers to, or null when the
     * token is not a reference to one of the three package models.
     *
     * @param  array{0: int, 1: string, 2: int}  $token
     * @param  list<string>  $importedShortNames
     */
    private function packageModelShortName(array $token, array $importedShortNames): ?string
    {
        [$id, $text] = $token;

        if ($id === T_STRING && in_array($text, $importedShortNames, true)) {
            return $text;
        }

        if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
            $fqcn = ltrim($text, '\\');
            if (isset(self::MODEL_IMPORTS[$fqcn])) {
                return self::MODEL_IMPORTS[$fqcn];
            }
        }

        return null;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesUnder(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
