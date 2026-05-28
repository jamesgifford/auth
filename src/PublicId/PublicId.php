<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId;

use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use JamesGifford\Auth\PublicId\Exceptions\InvalidPrefixException;

/**
 * Consumer-facing static API for the public_id subsystem.
 *
 * All methods delegate to services resolved from the Laravel container
 * (Generator, Validator, PublicIdConfig). Code that prefers explicit
 * dependencies can inject those services directly via DI rather than
 * calling through this class.
 *
 * Common usage:
 *
 *   $id = PublicId::generate('usr');                    // 'usr_a1b2...'
 *   $valid = PublicId::isValid($id);                    // true
 *   $valid = PublicId::isValid($id, 'usr');             // restricts to a prefix
 *   $result = PublicId::validate($id);                  // ValidationResult
 *   $prefix = PublicId::prefixOf($id);                  // 'usr' or null
 *   $columnSize = PublicId::maxLength();                // for migrations
 */
final class PublicId
{
    private function __construct() {}

    /**
     * Generate a new public ID for the given prefix.
     *
     * @throws InvalidPrefixException if the prefix is empty, too long,
     *                                or contains anything other than lowercase ASCII letters
     */
    public static function generate(string $prefix): string
    {
        return app(Generator::class)->generate($prefix);
    }

    /**
     * Validate a public ID, optionally requiring a specific prefix.
     */
    public static function validate(string $publicId, ?string $expectedPrefix = null): ValidationResult
    {
        return app(Validator::class)->validate($publicId, $expectedPrefix);
    }

    /**
     * Convenience boolean wrapper around {@see validate()}.
     */
    public static function isValid(string $publicId, ?string $expectedPrefix = null): bool
    {
        return app(Validator::class)->isValid($publicId, $expectedPrefix);
    }

    /**
     * Parse a public ID. Equivalent to {@see validate()} with no expected prefix.
     */
    public static function parse(string $publicId): ValidationResult
    {
        return app(Validator::class)->parse($publicId);
    }

    /**
     * Maximum total ID length given current config.
     *
     * Use this in migrations to size the public_id column:
     *
     *   $table->string('public_id', PublicId::maxLength())->unique();
     */
    public static function maxLength(): int
    {
        return app(PublicIdConfig::class)->totalMaxLength();
    }

    /**
     * Return the prefix portion of a public ID, or null if the input is invalid.
     */
    public static function prefixOf(string $publicId): ?string
    {
        $result = app(Validator::class)->parse($publicId);

        return $result->isValid() ? $result->prefix : null;
    }
}
