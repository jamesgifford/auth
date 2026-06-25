<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * The result of planning a SURGICAL reverse-modification of the consumer's User
 * model — i.e. removing only what install added (the HasPublicId/HasAccounts
 * imports + trait usage, and the publicIdPrefix() method), preserving all other
 * code. Holds both the original and the reverted code; the caller commits it
 * via {@see UserModelModifier::applyTransient()}.
 */
final readonly class UserModelReversion
{
    /**
     * @param  list<string>  $removedImports  Fully-qualified import names removed.
     * @param  list<string>  $removedTraits  Short trait names removed from the class.
     * @param  bool  $removedPublicIdPrefixMethod  Whether a publicIdPrefix() method was removed.
     * @param  ?string  $removedPrefixReturnValue  The literal it returned, when the method was the
     *                                             plain install-generated `return '<prefix>';` form.
     * @param  bool  $removedPrefixWasCustomized  True when the removed method body was NOT a single
     *                                            plain return-string (i.e. the consumer added logic).
     */
    public function __construct(
        public string $originalCode,
        public string $modifiedCode,
        public array $removedImports,
        public array $removedTraits,
        public bool $removedPublicIdPrefixMethod,
        public ?string $removedPrefixReturnValue,
        public bool $removedPrefixWasCustomized,
    ) {}

    public function hasChanges(): bool
    {
        return $this->originalCode !== $this->modifiedCode;
    }
}
