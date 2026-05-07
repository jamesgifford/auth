<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use RuntimeException;

/**
 * Thrown when {@see \Progravity\Auth\PublicId\PrefixRegistry} is asked for a
 * model's prefix but the model neither overrides `publicIdPrefix()` nor
 * appears in the config prefixes map.
 */
class UnregisteredModelException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            "Model '%s' has no registered public_id prefix.\n".
            "Either implement publicIdPrefix() on the model, or register it in\n".
            "config/progravity/auth.php under public_id.prefixes:\n\n".
            "    '%s' => 'your_prefix_here',",
            $modelClass,
            $modelClass,
        ));
    }
}
