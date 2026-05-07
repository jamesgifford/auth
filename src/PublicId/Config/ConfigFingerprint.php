<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Config;

final class ConfigFingerprint
{
    public function compute(PublicIdConfig $config): string
    {
        $fields = $config->fingerprintFields();
        ksort($fields);
        $json = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'sha256:'.hash('sha256', $json);
    }
}
