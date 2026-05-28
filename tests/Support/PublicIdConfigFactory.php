<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support;

use JamesGifford\Auth\PublicId\AlphabetRegistry;
use JamesGifford\Auth\PublicId\Checksum\PositionalSumChecksum;
use JamesGifford\Auth\PublicId\Config\PublicIdConfig;
use LogicException;

final class PublicIdConfigFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function default(array $overrides = []): PublicIdConfig
    {
        $config = array_replace_recursive([
            'prefix_max_length' => 7,
            'separator' => '_',
            'body' => [
                'length' => 18,
                'alphabet' => 'lowercase_alphanumeric',
            ],
            'checksum' => [
                'enabled' => true,
                'length' => 2,
                'strategy' => PositionalSumChecksum::class,
            ],
            'lock_file_path' => null,
            'prefixes' => [],
            'custom_alphabet_presets' => [],
        ], $overrides);

        self::assertSeparatorNotInAlphabet($config);

        return new PublicIdConfig($config, new AlphabetRegistry);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function assertSeparatorNotInAlphabet(array $config): void
    {
        $separator = $config['separator'];
        $alphabetValue = $config['body']['alphabet'];
        $resolved = (new AlphabetRegistry)->resolve($alphabetValue);

        if (! str_contains($resolved->toString(), $separator)) {
            return;
        }

        throw new LogicException(sprintf(
            "PublicIdConfigFactory: separator '%s' is a member of the resolved body alphabet '%s'. ".
            "Override the 'separator' key alongside 'body.alphabet'.\n\n".
            "Example:\n".
            "    PublicIdConfigFactory::default([\n".
            "        'separator' => '|',\n".
            "        'body' => ['alphabet' => '%s'],\n".
            '    ])',
            $separator,
            $alphabetValue,
            $alphabetValue,
        ));
    }
}
