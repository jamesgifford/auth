<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * The result of inspecting a consumer's DatabaseSeeder without modifying it.
 *
 * When the wiring service cannot safely automate changes (unparseable file,
 * more than one class, no Seeder subclass, no run() method), isModifiable()
 * is false and `unusualReason` carries a short human-readable explanation
 * suitable for surfacing in command output so consumers know what to wire by
 * hand.
 */
final readonly class DatabaseSeederAnalysis
{
    /**
     * @param  list<string>  $wiredSeeders  package seeder FQCNs already called, in canonical order
     */
    public function __construct(
        public bool $fileExists,
        public bool $parseable,
        public ?string $className,
        public ?string $namespace,
        public bool $extendsSeeder,
        public bool $hasRunMethod,
        public array $wiredSeeders,
        public ?string $unusualReason,
    ) {}

    public function isModifiable(): bool
    {
        return $this->fileExists
            && $this->parseable
            && $this->extendsSeeder
            && $this->hasRunMethod;
    }

    /**
     * The desired seeders not yet called, preserving the given order.
     *
     * @param  list<string>  $desired
     * @return list<string>
     */
    public function missing(array $desired): array
    {
        return array_values(array_filter(
            $desired,
            fn (string $fqcn): bool => ! in_array($fqcn, $this->wiredSeeders, true),
        ));
    }
}
