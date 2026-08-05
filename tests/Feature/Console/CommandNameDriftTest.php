<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Console;

use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards against command-name drift: every `jamesgifford:*` command name that
 * appears in a string literal anywhere in src/ (user-facing output, exception
 * messages, $this->call() targets, signatures) must be a command that is
 * actually registered. A message telling the user to run a command that does
 * not exist is worse than no message at all.
 *
 * Only string literals are scanned — comments and docblocks may freely discuss
 * hypothetical or historical command names.
 */
final class CommandNameDriftTest extends TestCase
{
    public function test_every_command_name_in_src_string_literals_is_registered(): void
    {
        $registered = array_keys(Artisan::all());

        $offenders = [];

        foreach ($this->phpFilesUnder($this->packageRoot().DIRECTORY_SEPARATOR.'src') as $file) {
            foreach ($this->commandNameLiterals((string) file_get_contents($file->getPathname())) as $line => $names) {
                foreach ($names as $name) {
                    if (! in_array($name, $registered, true)) {
                        $relative = substr($file->getPathname(), strlen($this->packageRoot()) + 1);
                        $offenders[] = "{$relative}:{$line} references unregistered command `{$name}`";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "String literals in src/ reference artisan commands that are not registered:\n%s\n".
            'Register the command, or correct the referenced name.',
            implode("\n", $offenders),
        ));
    }

    /**
     * Map of line number => list of `jamesgifford:*` command names found in
     * string-literal tokens on that line. Comments and docblocks are excluded
     * by construction: only T_CONSTANT_ENCAPSED_STRING and
     * T_ENCAPSED_AND_WHITESPACE tokens are inspected.
     *
     * @return array<int, list<string>>
     */
    private function commandNameLiterals(string $contents): array
    {
        $found = [];

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            // The colon anchor keeps this to command names: route names and
            // publish tags use hyphens (jamesgifford-auth.*), and config keys
            // use dots (jamesgifford.auth.*), so neither can match.
            if (preg_match_all('/jamesgifford:[a-z0-9:-]*[a-z0-9]/', $text, $matches) > 0) {
                foreach ($matches[0] as $name) {
                    $found[$line][] = $name;
                }
            }
        }

        return $found;
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
