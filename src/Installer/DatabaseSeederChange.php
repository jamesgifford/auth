<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * A planned edit to the consumer's DatabaseSeeder, held in memory. Producing
 * one writes nothing; {@see DatabaseSeederWiring::commit()} applies it.
 *
 * `addedSeeders` / `removedSeeders` are what actually changed — both empty
 * means the file is already in the desired state and the commit is a no-op.
 */
final readonly class DatabaseSeederChange
{
    /**
     * @param  list<string>  $addedSeeders
     * @param  list<string>  $removedSeeders
     */
    public function __construct(
        public string $originalCode,
        public string $modifiedCode,
        public array $addedSeeders,
        public array $removedSeeders,
    ) {}
}
