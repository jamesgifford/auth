<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Support;

/**
 * Stages a temporary tests/TestCase.php in the testbench skeleton's base
 * path — the path base_path() resolves to — and guarantees removal.
 *
 * The skeleton directory is shared across the suite, so a test that leaves a
 * file behind poisons every later one. Always call removeStagedTestCase() from
 * tearDown, unconditionally.
 */
trait StagesTestCase
{
    protected function stagedTestCasePath(): string
    {
        return base_path('tests'.DIRECTORY_SEPARATOR.'TestCase.php');
    }

    protected function stageTestCase(string $contents): string
    {
        $path = $this->stagedTestCasePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    protected function readStagedTestCase(): string
    {
        return (string) file_get_contents($this->stagedTestCasePath());
    }

    protected function removeStagedTestCase(): void
    {
        @unlink($this->stagedTestCasePath());
        @unlink($this->stagedTestCasePath().'.bak');
    }

    /**
     * A stock Laravel TestCase — no $seed property, no #[Seed] attribute, no
     * seeder() override.
     */
    protected function defaultTestCaseSource(): string
    {
        return <<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        abstract class TestCase extends BaseTestCase
        {
            use CreatesApplication;
        }

        PHP;
    }

    /**
     * A TestCase already seeding, for tests that stage an already-wired state
     * by hand rather than running install.
     */
    protected function seedingTestCaseSource(): string
    {
        return <<<'PHP'
        <?php

        namespace Tests;

        use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

        abstract class TestCase extends BaseTestCase
        {
            use CreatesApplication;

            protected $seed = true;
        }

        PHP;
    }
}
