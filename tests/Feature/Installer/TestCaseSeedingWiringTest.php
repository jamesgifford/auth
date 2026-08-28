<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use JamesGifford\Auth\Installer\TestCaseSeedingWiring;
use JamesGifford\Auth\Tests\Support\StagesTestCase;
use JamesGifford\Auth\Tests\TestCase;
use RuntimeException;

class TestCaseSeedingWiringTest extends TestCase
{
    use StagesTestCase;

    private TestCaseSeedingWiring $wiring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wiring = $this->app->make(TestCaseSeedingWiring::class);
    }

    protected function tearDown(): void
    {
        $this->removeStagedTestCase();

        parent::tearDown();
    }

    public function test_analyze_reports_missing_file(): void
    {
        $analysis = $this->wiring->analyze();

        $this->assertFalse($analysis->fileExists);
        $this->assertFalse($analysis->isModifiable());
        $this->assertFalse($analysis->needsWiring());
        $this->assertSame('file does not exist', $analysis->unusualReason);
    }

    public function test_analyze_reports_unparseable_file(): void
    {
        $this->stageTestCase('<?php this is not valid php {{{');

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->fileExists);
        $this->assertFalse($analysis->parseable);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_analyze_reports_multiple_classes_as_unusual(): void
    {
        $this->stageTestCase(<<<'PHP'
        <?php

        namespace Tests;

        class Helper {}

        abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}
        PHP);

        $analysis = $this->wiring->analyze();

        $this->assertFalse($analysis->isModifiable());
        $this->assertSame('multiple class declarations found in a single file', $analysis->unusualReason);
    }

    public function test_analyze_detects_default_testcase_needs_wiring(): void
    {
        $this->stageTestCase($this->defaultTestCaseSource());

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->isModifiable());
        $this->assertFalse($analysis->alreadySeeds);
        $this->assertTrue($analysis->needsWiring());
    }

    public function test_analyze_detects_existing_seed_property_true(): void
    {
        $this->stageTestCase($this->seedingTestCaseSource());

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->alreadySeeds);
        $this->assertFalse($analysis->needsWiring());
    }

    public function test_analyze_detects_existing_seed_property_false_as_deliberate_choice(): void
    {
        $this->stageTestCase(<<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        abstract class TestCase extends BaseTestCase
        {
            protected $seed = false;
        }
        PHP);

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->alreadySeeds);
        $this->assertFalse($analysis->needsWiring());
    }

    public function test_analyze_detects_seed_attribute(): void
    {
        $this->stageTestCase(<<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\Attributes\Seed;
        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        #[Seed]
        abstract class TestCase extends BaseTestCase
        {
        }
        PHP);

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->alreadySeeds);
    }

    public function test_analyze_detects_seeder_method_override(): void
    {
        $this->stageTestCase(<<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        abstract class TestCase extends BaseTestCase
        {
            protected function seeder()
            {
                return \Database\Seeders\DatabaseSeeder::class;
            }
        }
        PHP);

        $analysis = $this->wiring->analyze();

        $this->assertTrue($analysis->alreadySeeds);
    }

    public function test_wire_inserts_seed_property_after_trait_use(): void
    {
        $this->stageTestCase($this->defaultTestCaseSource());

        $analysis = $this->wiring->analyze();
        $change = $this->wiring->wire($analysis);

        $this->assertTrue($change->changed);
        $this->assertStringContainsString('protected $seed = true;', $change->modifiedCode);
        $this->assertStringContainsString('use CreatesApplication;', $change->modifiedCode);
        $this->assertStringContainsString('RefreshDatabase does not seed by default', $change->modifiedCode);
        $this->assertStringContainsString('Testing in your application', $change->modifiedCode);

        // Property comes after the trait use, matching where a human would
        // naturally place it.
        $traitPos = strpos($change->modifiedCode, 'use CreatesApplication;');
        $propPos = strpos($change->modifiedCode, 'protected $seed = true;');
        $this->assertIsInt($traitPos);
        $this->assertIsInt($propPos);
        $this->assertGreaterThan($traitPos, $propPos);
    }

    public function test_wire_is_a_noop_when_already_seeding(): void
    {
        $this->stageTestCase($this->seedingTestCaseSource());

        $analysis = $this->wiring->analyze();
        $change = $this->wiring->wire($analysis);

        $this->assertFalse($change->changed);
        $this->assertSame($change->originalCode, $change->modifiedCode);
    }

    public function test_wire_throws_when_not_modifiable(): void
    {
        $analysis = $this->wiring->analyze(); // no file staged

        $this->expectException(RuntimeException::class);

        $this->wiring->wire($analysis);
    }

    public function test_commit_writes_the_change_to_disk(): void
    {
        $this->stageTestCase($this->defaultTestCaseSource());

        $analysis = $this->wiring->analyze();
        $change = $this->wiring->wire($analysis);
        $this->wiring->commit($change);

        $this->assertStringContainsString('protected $seed = true;', $this->readStagedTestCase());

        // Re-analyzing now reports it as already wired.
        $this->assertTrue($this->wiring->analyze()->alreadySeeds);
    }

    public function test_commit_is_a_noop_when_unchanged(): void
    {
        $this->stageTestCase($this->seedingTestCaseSource());
        $before = $this->readStagedTestCase();

        $analysis = $this->wiring->analyze();
        $change = $this->wiring->wire($analysis);
        $this->wiring->commit($change);

        $this->assertSame($before, $this->readStagedTestCase());
    }

    public function test_wire_preserves_unrelated_content(): void
    {
        $source = <<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        abstract class TestCase extends BaseTestCase
        {
            use CreatesApplication;

            protected function setUp(): void
            {
                parent::setUp();

                // App-specific bootstrapping the package must not disturb.
                config(['app.debug' => true]);
            }
        }
        PHP;
        $this->stageTestCase($source);

        $analysis = $this->wiring->analyze();
        $change = $this->wiring->wire($analysis);

        $this->assertStringContainsString('App-specific bootstrapping the package must not disturb.', $change->modifiedCode);
        $this->assertStringContainsString("config(['app.debug' => true]);", $change->modifiedCode);
        $this->assertStringContainsString('protected $seed = true;', $change->modifiedCode);
    }
}
