<?php

declare(strict_types=1);

namespace Progravity\Auth\Installer;

/**
 * The result of planning a User model modification. Holds both the original
 * code and the modified code, plus a summary of what changed. Does not write
 * to disk by itself — the caller passes this through {@see UserModelModifier::write()}.
 */
final readonly class UserModelModification
{
    /**
     * @param  list<string>  $addedImports  Fully-qualified class names added to imports.
     * @param  list<string>  $addedTraits  Short trait names added to the class body.
     */
    public function __construct(
        public string $originalCode,
        public string $modifiedCode,
        public array $addedImports,
        public array $addedTraits,
        public bool $addedPublicIdPrefixMethod,
    ) {}

    /**
     * Render a simple line-by-line diff of original → modified. Lines that
     * exist in modified but not original are prefixed with "+"; lines that
     * exist in original but not modified are prefixed with "-". The output
     * is intended for human review in the installer, not as a true unified
     * diff suitable for patching.
     */
    public function diff(): string
    {
        $originalLines = explode("\n", $this->originalCode);
        $modifiedLines = explode("\n", $this->modifiedCode);

        $originalSet = array_count_values($originalLines);
        $modifiedSet = array_count_values($modifiedLines);

        $out = [];
        foreach ($modifiedLines as $line) {
            if (! isset($originalSet[$line]) || $originalSet[$line] <= 0) {
                $out[] = '+ '.$line;
            }
            if (isset($originalSet[$line]) && $originalSet[$line] > 0) {
                $originalSet[$line]--;
            }
        }
        foreach ($originalLines as $line) {
            if (! isset($modifiedSet[$line]) || $modifiedSet[$line] <= 0) {
                $out[] = '- '.$line;
            }
            if (isset($modifiedSet[$line]) && $modifiedSet[$line] > 0) {
                $modifiedSet[$line]--;
            }
        }

        return implode("\n", $out);
    }

    public function hasChanges(): bool
    {
        return $this->originalCode !== $this->modifiedCode;
    }
}
