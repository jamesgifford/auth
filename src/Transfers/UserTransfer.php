<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Transfers;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable snapshot of User state for use in events.
 *
 * Typed loosely as Model in {@see fromModel()} because the User class is
 * configurable via config('jamesgifford.auth.models.user') — the package
 * cannot type-hint a specific class. publicId is nullable so consumers who
 * opt out of HasPublicId on their User model are still supported.
 */
final readonly class UserTransfer
{
    public function __construct(
        public int $id,
        public ?string $publicId,
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(Model $user): self
    {
        return new self(
            id: $user->id,
            publicId: $user->public_id ?? null,
            name: $user->name,
            email: $user->email,
        );
    }
}
