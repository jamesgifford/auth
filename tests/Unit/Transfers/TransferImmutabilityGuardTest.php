<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Unit\Transfers;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
     * Every class declared beneath src/Transfers/ (any depth), excluding enums:
     * an enum cannot be declared readonly and its cases are immutable by
     * construction. A file whose class does not autoload from its path fails
     * loudly — skipping it would leave that transfer unguarded.
     *
     * @return list<ReflectionClass<object>>
     */
    private function transferClasses(): array
    {
        $classes = [];
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Transfers';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $fqcn = 'JamesGifford\\Auth\\Transfers\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            $this->assertTrue(
                class_exists($fqcn),
                $file->getPathname().' must declare '.$fqcn.' (PSR-4) so this guard can reflect it.',
            );

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isEnum()) {
                continue;
            }

            $classes[] = $reflection;
        }

        return $classes;
    }
}
