<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use Progravity\Auth\PublicId\Config\PublicIdConfig;

/**
 * Consumer-facing static API for the public_id subsystem.
 *
 * All methods delegate to services resolved from the Laravel container
 * (Generator, Validator, PublicIdConfig). Code that prefers explicit
 * dependencies can inject those services directly via DI rather than
 * calling through this class.
 */
final class PublicId
{
    private function __construct()
    {
    }

    public static function generate(string $prefix): string
    {
        return app(Generator::class)->generate($prefix);
    }

    public static function validate(string $publicId, ?string $expectedPrefix = null): ValidationResult
    {
        return app(Validator::class)->validate($publicId, $expectedPrefix);
    }

    public static function isValid(string $publicId, ?string $expectedPrefix = null): bool
    {
        return app(Validator::class)->isValid($publicId, $expectedPrefix);
    }

    public static function parse(string $publicId): ValidationResult
    {
        return app(Validator::class)->parse($publicId);
    }

    public static function maxLength(): int
    {
        return app(PublicIdConfig::class)->totalMaxLength();
    }

    public static function prefixOf(string $publicId): ?string
    {
        $result = app(Validator::class)->parse($publicId);

        return $result->isValid() ? $result->prefix : null;
    }
}
