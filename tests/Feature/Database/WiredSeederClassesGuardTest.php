<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Database;

use Illuminate\Database\Seeder;
use JamesGifford\Auth\Installer\DatabaseSeederWiring;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The wiring writes these class names into files inside consuming
 * applications. If a seeder is ever renamed or moved, the emitter and any test
 * asserting the same literal would agree with each other while every consumer's
 * DatabaseSeeder gained a fatal reference to a class that no longer exists.
 *
 * Pinning them against the autoloader is the only check that cannot drift with
 * the code it guards. Same reasoning as the package env-variable guard.
 */
class WiredSeederClassesGuardTest extends TestCase
{
    public function test_every_wired_class_exists_and_is_a_seeder(): void
    {
        $this->assertNotEmpty(
            DatabaseSeederWiring::CANONICAL_ORDER,
            'Sanity: the wiring must reference at least one seeder.',
        );

        foreach (DatabaseSeederWiring::CANONICAL_ORDER as $fqcn) {
            $this->assertTrue(
                class_exists($fqcn),
                $fqcn.' is written into consumers\' DatabaseSeeder files but does not autoload.',
            );

            $this->assertTrue(
                (new ReflectionClass($fqcn))->isSubclassOf(Seeder::class),
                $fqcn.' must extend '.Seeder::class.' to be callable via $this->call().',
            );
        }
    }

    public function test_the_canonical_order_is_roles_then_fixtures_then_offsets(): void
    {
        // Roles must exist before the fixtures referencing them, and offsets
        // must land above the fixtures they reserve room over.
        $this->assertSame(
            [
                DatabaseSeederWiring::ROLES,
                DatabaseSeederWiring::DEV_DATA,
                DatabaseSeederWiring::ID_OFFSETS,
            ],
            DatabaseSeederWiring::CANONICAL_ORDER,
        );
    }
}
