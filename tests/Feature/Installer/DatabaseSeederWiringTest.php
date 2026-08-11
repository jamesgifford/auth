<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use JamesGifford\Auth\Installer\DatabaseSeederChange;
use JamesGifford\Auth\Installer\DatabaseSeederWiring;
use JamesGifford\Auth\Tests\Support\StagesDatabaseSeeder;
use JamesGifford\Auth\Tests\TestCase;
use Throwable;

class DatabaseSeederWiringTest extends TestCase
{
    use StagesDatabaseSeeder;

    protected function tearDown(): void
    {
        $this->removeDatabaseSeeder();
        parent::tearDown();
    }

    public function test_it_reports_an_absent_file(): void
    {
        $this->removeDatabaseSeeder();

        $analysis = $this->wiring()->analyze();

        $this->assertFalse($analysis->fileExists);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_it_analyzes_a_stock_database_seeder(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());

        $analysis = $this->wiring()->analyze();

        $this->assertTrue($analysis->fileExists);
        $this->assertTrue($analysis->parseable);
        $this->assertTrue($analysis->extendsSeeder);
        $this->assertTrue($analysis->hasRunMethod);
        $this->assertTrue($analysis->isModifiable());
        $this->assertSame([], $analysis->wiredSeeders);
    }

    public function test_it_detects_an_imported_class_reference(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;
        use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(AccountRoleSeeder::class);
            }
        }
        PHP);

        $this->assertSame(
            [DatabaseSeederWiring::ROLES],
            $this->wiring()->analyze()->wiredSeeders,
        );
    }

    public function test_it_detects_a_fully_qualified_reference(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);
            }
        }
        PHP);

        $this->assertSame(
            [DatabaseSeederWiring::DEV_DATA],
            $this->wiring()->analyze()->wiredSeeders,
        );
    }

    public function test_it_detects_the_array_call_form(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call([
                    \JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class,
                    ProductSeeder::class,
                ]);
            }
        }
        PHP);

        $this->assertSame(
            [DatabaseSeederWiring::ROLES],
            $this->wiring()->analyze()->wiredSeeders,
        );
    }

    public function test_it_detects_a_call_nested_in_an_environment_guard(): void
    {
        // The form README documented before this release. It must count as
        // already wired, or setup would add a duplicate.
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                if (app()->environment('local', 'staging')) {
                    $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);
                }
            }
        }
        PHP);

        $this->assertSame(
            [DatabaseSeederWiring::DEV_DATA],
            $this->wiring()->analyze()->wiredSeeders,
        );
    }

    public function test_it_refuses_an_unparseable_file(): void
    {
        $this->stageDatabaseSeeder('<?php this is not php {{{');

        $analysis = $this->wiring()->analyze();

        $this->assertFalse($analysis->parseable);
        $this->assertFalse($analysis->isModifiable());
        $this->assertNotNull($analysis->unusualReason);
    }

    public function test_it_refuses_a_class_that_does_not_extend_seeder(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        class DatabaseSeeder
        {
            public function run(): void {}
        }
        PHP);

        $analysis = $this->wiring()->analyze();

        $this->assertFalse($analysis->extendsSeeder);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_it_refuses_multiple_classes_in_one_file(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class Helper {}

        class DatabaseSeeder extends Seeder
        {
            public function run(): void {}
        }
        PHP);

        $this->assertFalse($this->wiring()->analyze()->isModifiable());
    }

    public function test_it_refuses_a_class_without_a_run_method(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
        }
        PHP);

        $analysis = $this->wiring()->analyze();

        $this->assertFalse($analysis->hasRunMethod);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_it_accepts_a_seeder_class_under_a_different_name(): void
    {
        // Targeting is name-agnostic: the requirement is one Seeder subclass in
        // the file, not a class literally named DatabaseSeeder.
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class RootSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(ProductSeeder::class);
            }
        }
        PHP);

        $analysis = $this->wiring()->analyze();

        $this->assertTrue($analysis->isModifiable());
        $this->assertSame('RootSeeder', $analysis->className);
    }

    public function test_missing_returns_desired_seeders_not_yet_wired(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());

        $missing = $this->wiring()->analyze()->missing([
            DatabaseSeederWiring::ROLES,
            DatabaseSeederWiring::ID_OFFSETS,
        ]);

        $this->assertSame(
            [DatabaseSeederWiring::ROLES, DatabaseSeederWiring::ID_OFFSETS],
            $missing,
        );
    }

    public function test_it_wires_all_three_seeders_in_canonical_order(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());
        $wiring = $this->wiring();

        $change = $wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER);
        $wiring->commit($change);

        $contents = $this->readDatabaseSeeder();

        $rolesAt = strpos($contents, 'AccountRoleSeeder::class');
        $devAt = strpos($contents, 'DevDataSeeder::class');
        $offsetsAt = strpos($contents, 'ApplyIdOffsetsSeeder::class');

        $this->assertIsInt($rolesAt);
        $this->assertIsInt($devAt);
        $this->assertIsInt($offsetsAt);
        $this->assertLessThan($devAt, $rolesAt);
        $this->assertLessThan($offsetsAt, $devAt);
    }

    public function test_it_preserves_the_apps_own_seeder_and_docblock(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());
        $wiring = $this->wiring();
        $wiring->commit($wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringContainsString('$this->call(ProductSeeder::class);', $contents);
        $this->assertStringContainsString("Seed the application's database.", $contents);
        $this->assertStringContainsString("// The app's own seeding.", $contents);
    }

    public function test_package_calls_precede_the_apps_own_seeders(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());
        $wiring = $this->wiring();
        $wiring->commit($wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER));

        $contents = $this->readDatabaseSeeder();

        $this->assertLessThan(
            strpos($contents, 'ProductSeeder::class'),
            strpos($contents, 'AccountRoleSeeder::class'),
            'Roles must be seeded before app seeders that may reference them.',
        );
    }

    public function test_wiring_is_idempotent(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());
        $wiring = $this->wiring();
        $wiring->commit($wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER));
        $afterFirst = $this->readDatabaseSeeder();

        $second = $wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER);

        $this->assertSame([], $second->addedSeeders);
        $wiring->commit($second);
        $this->assertSame($afterFirst, $this->readDatabaseSeeder());
        $this->assertSame(1, substr_count($this->readDatabaseSeeder(), 'AccountRoleSeeder::class'));
    }

    public function test_it_inserts_only_the_missing_seeder(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(\JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class);
            }
        }
        PHP);

        $wiring = $this->wiring();
        $change = $wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER);

        $this->assertSame(
            [DatabaseSeederWiring::DEV_DATA, DatabaseSeederWiring::ID_OFFSETS],
            $change->addedSeeders,
        );

        $wiring->commit($change);
        $this->assertSame(1, substr_count($this->readDatabaseSeeder(), 'AccountRoleSeeder::class'));
    }

    public function test_it_writes_a_stub_when_the_file_is_absent(): void
    {
        $this->removeDatabaseSeeder();
        $wiring = $this->wiring();

        $stub = $wiring->stub(DatabaseSeederWiring::CANONICAL_ORDER);
        $this->stageDatabaseSeeder($stub);

        $analysis = $wiring->analyze();

        $this->assertTrue($analysis->isModifiable());
        $this->assertSame(DatabaseSeederWiring::CANONICAL_ORDER, $analysis->wiredSeeders);
        $this->assertStringContainsString('namespace Database\Seeders;', $stub);
        $this->assertStringContainsString('declare(strict_types=1);', $stub);
    }

    public function test_a_failed_verification_restores_the_original_file(): void
    {
        $original = $this->defaultDatabaseSeederSource();
        $this->stageDatabaseSeeder($original);
        $wiring = $this->wiring();

        $broken = new DatabaseSeederChange(
            originalCode: $original,
            modifiedCode: '<?php this will not parse {{{',
            addedSeeders: [],
            removedSeeders: [],
        );

        try {
            $wiring->commit($broken);
            $this->fail('commit() should throw when the written file does not parse.');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame($original, $this->readDatabaseSeeder());
        $this->assertFileDoesNotExist($this->databaseSeederPath().'.bak');
    }

    public function test_wire_then_unwire_restores_the_file_byte_for_byte(): void
    {
        $original = $this->defaultDatabaseSeederSource();
        $this->stageDatabaseSeeder($original);
        $wiring = $this->wiring();

        $wiring->commit($wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER));
        $this->assertNotSame($original, $this->readDatabaseSeeder());

        $wiring->commit($wiring->unwire($wiring->analyze()));

        $this->assertSame(
            $original,
            $this->readDatabaseSeeder(),
            'Unwiring must return the file to its exact pre-wiring state.',
        );
    }

    public function test_unwire_keeps_the_apps_own_seeders(): void
    {
        $this->stageDatabaseSeeder($this->defaultDatabaseSeederSource());
        $wiring = $this->wiring();
        $wiring->commit($wiring->wire($wiring->analyze(), DatabaseSeederWiring::CANONICAL_ORDER));
        $wiring->commit($wiring->unwire($wiring->analyze()));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringContainsString('$this->call(ProductSeeder::class);', $contents);
        $this->assertStringNotContainsString('AccountRoleSeeder', $contents);
        $this->assertStringNotContainsString('ApplyIdOffsetsSeeder', $contents);
    }

    public function test_unwire_removes_only_our_entries_from_an_array_call(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call([
                    \JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class,
                    ProductSeeder::class,
                ]);
            }
        }
        PHP);

        $wiring = $this->wiring();
        $wiring->commit($wiring->unwire($wiring->analyze()));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringContainsString('ProductSeeder::class', $contents);
        $this->assertStringNotContainsString('AccountRoleSeeder', $contents);
    }

    public function test_unwire_removes_an_emptied_environment_guard(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                if (app()->environment('local', 'staging')) {
                    $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);
                }
            }
        }
        PHP);

        $wiring = $this->wiring();
        $wiring->commit($wiring->unwire($wiring->analyze()));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringNotContainsString('DevDataSeeder', $contents);
        $this->assertStringNotContainsString('app()->environment', $contents);
    }

    public function test_unwire_keeps_a_guard_that_has_an_else_branch(): void
    {
        // We only delete control structures that become genuinely dead. An
        // else branch is the developer's code and must survive.
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                if (app()->environment('local')) {
                    $this->call(\JamesGifford\Auth\Database\DevDataSeeder::class);
                } else {
                    $this->call(ProductSeeder::class);
                }
            }
        }
        PHP);

        $wiring = $this->wiring();
        $wiring->commit($wiring->unwire($wiring->analyze()));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringNotContainsString('DevDataSeeder', $contents);
        $this->assertStringContainsString('ProductSeeder::class', $contents);
        $this->assertStringContainsString('app()->environment', $contents);
    }

    public function test_unwire_leaves_an_emptied_run_method_valid(): void
    {
        $wiring = $this->wiring();
        $this->stageDatabaseSeeder($wiring->stub(DatabaseSeederWiring::CANONICAL_ORDER));

        $wiring->commit($wiring->unwire($wiring->analyze()));

        $analysis = $wiring->analyze();

        $this->assertTrue($analysis->parseable);
        $this->assertTrue($analysis->hasRunMethod);
        $this->assertSame([], $analysis->wiredSeeders);
    }

    public function test_unwire_removes_a_now_unused_package_import(): void
    {
        $this->stageDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;
        use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(AccountRoleSeeder::class);
                $this->call(ProductSeeder::class);
            }
        }
        PHP);

        $wiring = $this->wiring();
        $wiring->commit($wiring->unwire($wiring->analyze()));

        $contents = $this->readDatabaseSeeder();

        $this->assertStringNotContainsString('AccountRoleSeeder', $contents);
        $this->assertStringContainsString('use Illuminate\Database\Seeder;', $contents);
    }

    private function wiring(): DatabaseSeederWiring
    {
        return $this->app->make(DatabaseSeederWiring::class);
    }
}
