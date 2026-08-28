<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * A planned edit to the consumer's tests/TestCase.php, held in memory.
 * Producing one writes nothing; {@see TestCaseSeedingWiring::commit()}
 * applies it.
 */
final readonly class TestCaseSeedingChange
{
    public function __construct(
        public string $originalCode,
        public string $modifiedCode,
        public bool $changed,
    ) {}
}
