<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use PhpParser\Parser;
use RuntimeException;
use Throwable;

/**
 * Commits new code to a PHP file with a TRANSIENT backup: copy the file to
 * .bak first, write, verify the result is valid PHP (plus an optional caller
 * check), then DELETE the .bak on success. On any failure the file is restored
 * from the backup and the .bak removed — so the file returns to its exact
 * pre-edit state and NO .bak is ever left behind.
 *
 * Shared by every editor that rewrites a consumer's source file
 * ({@see UserModelModifier}, {@see DatabaseSeederWiring}), so the
 * restore-on-failure guarantee has exactly one implementation.
 */
final class TransientFileWriter
{
    public function __construct(private readonly Parser $parser) {}

    /**
     * @param  ?Closure():void  $verify  Optional semantic check; should throw on failure.
     */
    public function apply(string $filePath, string $newCode, ?Closure $verify = null): void
    {
        $backupPath = $filePath.'.bak';
        copy($filePath, $backupPath);

        try {
            file_put_contents($filePath, $newCode);

            // Validity gate: the written file must still parse as PHP.
            $written = (string) file_get_contents($filePath);
            if ($this->parser->parse($written) === null) {
                throw new RuntimeException('the edited file did not parse as valid PHP');
            }

            if ($verify !== null) {
                $verify();
            }
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                file_put_contents($filePath, (string) file_get_contents($backupPath));
            }
            @unlink($backupPath);

            throw $e;
        }

        @unlink($backupPath);
    }
}
