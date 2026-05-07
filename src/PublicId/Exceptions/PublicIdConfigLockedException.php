<?php

declare(strict_types=1);

namespace Progravity\Auth\PublicId\Exceptions;

use RuntimeException;

class PublicIdConfigLockedException extends RuntimeException
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $diff
     */
    public function __construct(
        public readonly string $lockedFingerprint,
        public readonly string $currentFingerprint,
        public readonly array $diff,
    ) {
        parent::__construct($this->buildMessage());
    }

    private function buildMessage(): string
    {
        $lines = [];
        $lines[] = 'Public ID configuration has drifted from locked fingerprint.';
        $lines[] = '';
        $lines[] = "  Locked:  {$this->lockedFingerprint}";
        $lines[] = "  Current: {$this->currentFingerprint}";
        $lines[] = '';
        $lines[] = '  Changed fields:';

        foreach ($this->diff as $key => $change) {
            $from = $this->renderValue($change['from']);
            $to = $this->renderValue($change['to']);
            $lines[] = "    {$key}: {$from} → {$to}";
        }

        $lines[] = '';
        $lines[] = '  See documentation for resolution: revert config or run';
        $lines[] = '  `php artisan progravity:public-id:reset` (destructive).';

        return implode("\n", $lines);
    }

    private function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '<missing>';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export($value, true);
    }
}
