<?php

declare(strict_types=1);

namespace JamesGifford\Auth\PublicId\Config;

/**
 * Computes a deterministic fingerprint over the locked subset of config.
 *
 * Output format: `sha256:{64 hex chars}`. The `sha256:` prefix is reserved
 * so the algorithm could be swapped in the future without ambiguity.
 */
final class ConfigFingerprint
{
    /**
     * Hash the locked-subset fields in a deterministic, key-sorted form.
     */
    public function compute(PublicIdConfig $config): string
    {
        $fields = $config->fingerprintFields();
        ksort($fields);
        $json = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'sha256:'.hash('sha256', $json);
    }
}
