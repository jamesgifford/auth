<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use Progravity\Auth\PublicId\Checksum\ChecksumStrategy;
use Progravity\Auth\PublicId\Config\PublicIdConfig;
use Progravity\Auth\PublicId\Exceptions\InvalidPrefixException;

/**
 * Builds full public IDs of the form `{prefix}{separator}{body}{checksum}`.
 *
 * This is the underlying service behind {@see PublicId::generate()}. Inject
 * it directly via DI when you prefer explicit dependencies; otherwise call
 * the static facade.
 */
final class Generator
{
    private readonly ChecksumStrategy $checksumStrategy;

    public function __construct(private readonly PublicIdConfig $config)
    {
        $strategyClass = $config->checksumStrategy();
        $this->checksumStrategy = new $strategyClass();
    }

    /**
     * Generate a complete public ID for the given prefix.
     *
     * @throws InvalidPrefixException if the prefix is empty, too long,
     *                                or contains anything other than lowercase ASCII letters
     */
    public function generate(string $prefix): string
    {
        $this->assertValidPrefix($prefix);

        $body = $this->generateBody();
        $checksum = $this->computeChecksum($body);

        return $prefix.$this->config->separator().$body.$checksum;
    }

    /**
     * Generate just the random body portion (no prefix, separator, or checksum).
     * Uses random_int() for cryptographic-quality randomness.
     */
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

    /**
     * Compute the configured checksum for a given body. Returns an empty
     * string when checksums are disabled.
     */
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
