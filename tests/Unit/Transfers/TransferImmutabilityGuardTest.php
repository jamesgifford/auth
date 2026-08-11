<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\Transfers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Transfers are the package's boundary type: services return them and events
 * carry them, so a consumer holding one must not be able to mutate it and hand
 * it back expecting the change to mean something. Every one is declared
 * readonly, and this pins that.
 *
 * Reflecting over the whole directory rather than naming a class by hand is the
 * point. The previous version of this guard instantiated a single AccountTransfer
 * and asserted PHP threw on write — which tested the language more than the
 * package, left the other four transfers unguarded, and cost a full migration
 * run plus two database rows to do it.
 */
class TransferImmutabilityGuardTest extends TestCase
{
    public function test_every_transfer_is_readonly(): void
    {
        $transfers = $this->transferClasses();

        $this->assertNotEmpty($transfers, 'Sanity: src/Transfers must contain at least one class.');

        foreach ($transfers as $transfer) {
            $this->assertTrue(
                $transfer->isReadOnly(),
                $transfer->getName().' must be declared readonly — transfers cross the package '
                    .'boundary and must not be mutable by consumers.',
            );
        }
    }

    /**
     * Every class declared in src/Transfers/, excluding enums: an enum cannot be
     * declared readonly and its cases are immutable by construction.
     *
     * @return list<ReflectionClass<object>>
     */
    private function transferClasses(): array
    {
        $classes = [];

        foreach ((array) glob(dirname(__DIR__, 3).'/src/Transfers/*.php') as $file) {
            $fqcn = 'JamesGifford\\Auth\\Transfers\\'.basename((string) $file, '.php');

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isEnum()) {
                continue;
            }

            $classes[] = $reflection;
        }

        return $classes;
    }
}
