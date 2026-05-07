<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use Progravity\Auth\PublicId\Checksum\ChecksumStrategy;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Exceptions\InvalidPrefixException;

final class Generator
{
    private readonly ChecksumStrategy $checksumStrategy;

    public function __construct(private readonly PublicIdConfig $config)
    {
        $strategyClass = $config->checksumStrategy();
        $this->checksumStrategy = new $strategyClass();
    }

    public function generate(string $prefix): string
    {
        $this->assertValidPrefix($prefix);

        $body = $this->generateBody();
        $checksum = $this->computeChecksum($body);

        return $prefix.$this->config->separator().$body.$checksum;
    }

    public function generateBody(): string
    {
        $alphabet = $this->config->bodyAlphabet();
        $size = $alphabet->size();
        $length = $this->config->bodyLength();

        $body = '';
        for ($i = 0; $i < $length; $i++) {
            $body .= $alphabet->charAt(random_int(0, $size - 1));
        }

        return $body;
    }

    public function computeChecksum(string $body): string
    {
        return $this->checksumStrategy->compute(
            $body,
            $this->config->bodyAlphabet(),
            $this->config->checksumLength(),
        );
    }

    private function assertValidPrefix(string $prefix): void
    {
        $max = $this->config->prefixMaxLength();
        if (preg_match('/^[a-z]{1,'.$max.'}$/', $prefix) !== 1) {
            throw InvalidPrefixException::forPrefix($prefix, $max);
        }
    }
}
