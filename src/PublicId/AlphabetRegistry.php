<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId;

use OutOfBoundsException;

final class AlphabetRegistry
{
    private const PRESETS = [
        'lowercase_alpha' => 'abcdefghijklmnopqrstuvwxyz',
        'lowercase_alphanumeric' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        'uppercase_alpha' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'uppercase_alphanumeric' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'mixed_alphanumeric' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'crockford' => '0123456789ABCDEFGHJKMNPQRSTVWXYZ',
        'nolookalikes' => 'abcdefghjkmnpqrstuvwxyz23456789',
    ];

    /**
     * @var array<string, string>
     */
    private array $presets;

    /**
     * @var array<string, Alphabet>
     */
    private array $cache = [];

    /**
     * @param  array<string, string>  $customPresets
     */
    public function __construct(array $customPresets = [])
    {
        $presets = self::PRESETS;

        foreach ($customPresets as $name => $characters) {
            $alphabet = new Alphabet($characters);
            $presets[$name] = $characters;
            $this->cache[$name] = $alphabet;
        }

        $this->presets = $presets;
    }

    public function resolve(string $value): Alphabet
    {
        if (array_key_exists($value, $this->presets)) {
            return $this->materialize($value);
        }

        return new Alphabet($value);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->presets);
    }

    public function get(string $name): Alphabet
    {
        if (! array_key_exists($name, $this->presets)) {
            throw new OutOfBoundsException(
                "Unknown alphabet preset '{$name}'."
            );
        }

        return $this->materialize($name);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = array_keys($this->presets);
        sort($names);

        return $names;
    }

    private function materialize(string $name): Alphabet
    {
        if (! array_key_exists($name, $this->cache)) {
            $this->cache[$name] = new Alphabet($this->presets[$name]);
        }

        return $this->cache[$name];
    }
}
