<?php

declare(strict_types=1);

namespace Progravity\Auth\Installer;

/**
 * The result of inspecting a consumer's User model file without modifying it.
 *
 * `hasUnusualStructure` is true when the modifier cannot safely automate
 * changes (custom base class, multiple classes per file, no parseable class,
 * etc.). `unusualReason` carries a short human-readable explanation suitable
 * for surfacing in error messages so consumers know what to fix manually.
 */
final readonly class UserModelAnalysis
{
    public function __construct(
        public bool $fileExists,
        public bool $parseable,
        public ?string $className,
        public ?string $namespace,
        public bool $extendsAuthenticatable,
        public bool $hasHasPublicIdTrait,
        public bool $hasHasAccountsTrait,
        public bool $hasPublicIdPrefixMethod,
        public bool $hasUnusualStructure,
        public ?string $unusualReason,
    ) {}

    public function needsModification(): bool
    {
        return ! $this->hasHasPublicIdTrait
            || ! $this->hasHasAccountsTrait
            || ! $this->hasPublicIdPrefixMethod;
    }

    public function isModifiable(): bool
    {
        return $this->fileExists
            && $this->parseable
            && $this->extendsAuthenticatable
            && ! $this->hasUnusualStructure;
    }
}
