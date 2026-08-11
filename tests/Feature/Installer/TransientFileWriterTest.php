<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use JamesGifford\Auth\Installer\TransientFileWriter;
use JamesGifford\Auth\Tests\TestCase;
use RuntimeException;

class TransientFileWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-writer-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_it_applies_new_code_and_leaves_no_backup(): void
    {
        $target = $this->tmpDir.DIRECTORY_SEPARATOR.'Target.php';
        file_put_contents($target, "<?php\n\n// original\n");

        $this->writer()->apply($target, "<?php\n\n// updated\n");

        $this->assertSame("<?php\n\n// updated\n", (string) file_get_contents($target));
        $this->assertFileDoesNotExist($target.'.bak');
    }

    public function test_a_blocked_backup_aborts_before_touching_the_target(): void
    {
        $target = $this->tmpDir.DIRECTORY_SEPARATOR.'Target.php';
        file_put_contents($target, "<?php\n\n// original\n");

        // A directory at the .bak path makes copy() fail: with no restore
        // point, apply() must refuse to touch the target at all.
        mkdir($target.'.bak');

        try {
            $this->writer()->apply($target, "<?php\n\n// new code\n");
            $this->fail('apply() must throw when the backup cannot be created.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            "<?php\n\n// original\n",
            (string) file_get_contents($target),
            'The target must be untouched when no backup could be taken.',
        );
    }

    private function writer(): TransientFileWriter
    {
        return $this->app->make(TransientFileWriter::class);
    }
}
