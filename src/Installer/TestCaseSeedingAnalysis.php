<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * The result of inspecting a consumer's tests/TestCase.php without modifying
 * it.
 *
 * When the wiring service cannot safely automate changes (unparseable file,
 * more than one class), isModifiable() is false and `unusualReason` carries a
 * short human-readable explanation suitable for surfacing in command output.
 *
 * `alreadySeeds` is true when the class already seeds some other way — a
 * `$seed` property (any value, including `false`: an explicit opt-out is
 * still a deliberate choice), a `#[Seed]` attribute, or an overridden
 * `seeder()` method (which takes priority over `$seed` entirely). Wiring is
 * a no-op in that case, regardless of isModifiable().
 */
final readonly class TestCaseSeedingAnalysis
{
    public function __construct(
        public bool $fileExists,
        public bool $parseable,
        public ?string $className,
        public bool $alreadySeeds,
        public ?string $unusualReason,
    ) {}

    public function isModifiable(): bool
    {
        return $this->fileExists && $this->parseable && $this->className !== null;
    }

    public function needsWiring(): bool
    {
        return $this->isModifiable() && ! $this->alreadySeeds;
    }
}
