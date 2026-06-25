<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use JamesGifford\Auth\Installer\UserModelModifier;
use JamesGifford\Auth\Tests\TestCase;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

class UserModelModifierTest extends TestCase
{
    private UserModelModifier $modifier;

    private string $tmpDir;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->modifier = new UserModelModifier(
            $this->app->make(Parser::class),
            new Standard,
        );
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-modifier-'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
            @unlink($file.'.bak');
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ---- analyze() ----

    public function test_analyze_identifies_standard_laravel_user_as_modifiable(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('StandardLaravelUser'));

        $this->assertTrue($analysis->fileExists);
        $this->assertTrue($analysis->parseable);
        $this->assertSame('StandardLaravelUser', $analysis->className);
        $this->assertTrue($analysis->extendsAuthenticatable);
        $this->assertTrue($analysis->isModifiable());
        $this->assertTrue($analysis->needsModification());
    }

    public function test_analyze_identifies_missing_traits_and_method(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('StandardLaravelUser'));

        $this->assertFalse($analysis->hasHasPublicIdTrait);
        $this->assertFalse($analysis->hasHasAccountsTrait);
        $this->assertFalse($analysis->hasPublicIdPrefixMethod);
    }

    public function test_analyze_identifies_present_traits_when_already_there(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithBothPackageTraits'));

        $this->assertTrue($analysis->hasHasPublicIdTrait);
        $this->assertTrue($analysis->hasHasAccountsTrait);
        $this->assertTrue($analysis->hasPublicIdPrefixMethod);
        $this->assertFalse($analysis->needsModification());
    }

    public function test_analyze_identifies_custom_base_as_unmodifiable(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithCustomBase'));

        $this->assertFalse($analysis->extendsAuthenticatable);
        $this->assertTrue($analysis->hasUnusualStructure);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_analyze_returns_unusual_reason_for_unmodifiable(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithCustomBase'));

        $this->assertNotNull($analysis->unusualReason);
        $this->assertStringContainsString('extends', $analysis->unusualReason);
    }

    public function test_analyze_returns_unusual_reason_for_multiple_classes(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithUnusualStructure'));

        $this->assertTrue($analysis->hasUnusualStructure);
        $this->assertNotNull($analysis->unusualReason);
        $this->assertStringContainsString('multiple', $analysis->unusualReason);
    }

    public function test_analyze_returns_file_exists_false_for_missing_file(): void
    {
        $analysis = $this->modifier->analyze('/nonexistent/path/to/User.php');

        $this->assertFalse($analysis->fileExists);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_analyze_returns_parseable_false_for_malformed_php(): void
    {
        $tmp = $this->tmpDir.DIRECTORY_SEPARATOR.'broken.php';
        file_put_contents($tmp, "<?php\n\nclass Broken { fn { invalid syntax }\n");
        $this->createdFiles[] = $tmp;

        $analysis = $this->modifier->analyze($tmp);

        $this->assertTrue($analysis->fileExists);
        $this->assertFalse($analysis->parseable);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_analyze_detects_existing_public_id_prefix_method(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithCustomPrefix'));

        $this->assertTrue($analysis->hasPublicIdPrefixMethod);
    }

    // ---- modify() ----

    public function test_modify_adds_has_public_id_import_when_missing(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        $this->assertStringContainsString('use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;', $mod->modifiedCode);
        $this->assertContains('JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId', $mod->addedImports);
    }

    public function test_modify_adds_has_accounts_import_when_missing(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        $this->assertStringContainsString('use JamesGifford\\Auth\\Concerns\\HasAccounts;', $mod->modifiedCode);
        $this->assertContains('JamesGifford\\Auth\\Concerns\\HasAccounts', $mod->addedImports);
    }

    public function test_modify_adds_trait_usage_to_class_body(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        $this->assertStringContainsString('use HasPublicId, HasAccounts;', $mod->modifiedCode);
        $this->assertContains('HasPublicId', $mod->addedTraits);
        $this->assertContains('HasAccounts', $mod->addedTraits);
    }

    public function test_modify_adds_public_id_prefix_method(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        $this->assertStringContainsString('public function publicIdPrefix(): string', $mod->modifiedCode);
        $this->assertStringContainsString("return 'user';", $mod->modifiedCode);
        $this->assertTrue($mod->addedPublicIdPrefixMethod);
    }

    public function test_modify_does_not_overwrite_existing_public_id_prefix(): void
    {
        $file = $this->copyFixtureToTmp('UserWithCustomPrefix');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        // The original 'mbr' return must still be present; no 'user' is added.
        $this->assertStringContainsString("return 'mbr';", $mod->modifiedCode);
        $this->assertStringNotContainsString("return 'user';", $mod->modifiedCode);
        $this->assertFalse($mod->addedPublicIdPrefixMethod);
    }

    public function test_modify_idempotent_when_everything_already_present(): void
    {
        $file = $this->copyFixtureToTmp('UserWithBothPackageTraits');
        $analysis = $this->modifier->analyze($file);

        $mod = $this->modifier->modify($file, $analysis);

        $this->assertSame([], $mod->addedImports);
        $this->assertSame([], $mod->addedTraits);
        $this->assertFalse($mod->addedPublicIdPrefixMethod);
        $this->assertFalse($mod->hasChanges());
    }

    public function test_modify_produces_valid_php(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        // Re-parse to confirm validity.
        $parser = $this->app->make(Parser::class);
        $ast = $parser->parse($mod->modifiedCode);

        $this->assertIsArray($ast);
        $this->assertNotEmpty($ast);
    }

    public function test_modify_preserves_other_file_content(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        // The original namespace must be preserved.
        $this->assertStringContainsString('namespace JamesGifford\\Auth\\Tests\\Support\\Fixtures\\UserModels;', $mod->modifiedCode);
        // The original use statements must still be present.
        $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;', $mod->modifiedCode);
        $this->assertStringContainsString('use Illuminate\\Notifications\\Notifiable;', $mod->modifiedCode);
        // Existing class members must still be present.
        $this->assertStringContainsString('protected $fillable', $mod->modifiedCode);
        $this->assertStringContainsString('protected $hidden', $mod->modifiedCode);
        $this->assertStringContainsString('casts()', $mod->modifiedCode);
        // Existing trait usage preserved.
        $this->assertStringContainsString('use HasFactory, Notifiable;', $mod->modifiedCode);
    }

    public function test_modify_produces_readable_diff(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        $diff = $mod->diff();

        $this->assertNotSame('', $diff);
        $this->assertStringContainsString('use JamesGifford\\Auth\\PublicId\\Concerns\\HasPublicId;', $diff);
        $this->assertStringContainsString('use HasPublicId, HasAccounts;', $diff);
    }

    public function test_modify_throws_for_unmodifiable_analysis(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('UserWithCustomBase'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot modify User model');

        $this->modifier->modify($this->fixturePath('UserWithCustomBase'), $analysis);
    }

    public function test_modified_class_can_be_loaded_and_instantiated(): void
    {
        // Copy fixture, modify, write under a unique class name, require it.
        $uniqueClass = 'StandardLaravelUserMod'.uniqid('', false);
        $sourceFile = $this->fixturePath('StandardLaravelUser');
        $code = (string) file_get_contents($sourceFile);
        // Rewrite the namespace+classname so the loaded class doesn't collide
        // with the fixture's original class.
        $code = preg_replace('/namespace [^;]+;/', "namespace JamesGifford\\Auth\\Tests\\Tmp\\Mod{$uniqueClass};", $code);
        $code = preg_replace('/class StandardLaravelUser /', "class {$uniqueClass} ", $code);

        $tmpFile = $this->tmpDir.DIRECTORY_SEPARATOR.$uniqueClass.'.php';
        file_put_contents($tmpFile, $code);
        $this->createdFiles[] = $tmpFile;

        $analysis = $this->modifier->analyze($tmpFile);
        $mod = $this->modifier->modify($tmpFile, $analysis);
        $this->modifier->write($tmpFile, $mod, createBackup: false);

        require $tmpFile;

        $fqcn = "JamesGifford\\Auth\\Tests\\Tmp\\Mod{$uniqueClass}\\{$uniqueClass}";
        $this->assertTrue(class_exists($fqcn));

        $instance = new $fqcn;
        $this->assertSame('user', $instance->publicIdPrefix());
    }

    // ---- write() / restore() ----

    public function test_write_creates_backup_when_create_backup_is_true(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $originalContent = (string) file_get_contents($file);

        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        $this->modifier->write($file, $mod, createBackup: true);

        $this->assertFileExists($file.'.bak');
        $this->assertSame($originalContent, file_get_contents($file.'.bak'));
    }

    public function test_write_skips_backup_when_create_backup_is_false(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');

        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        $this->modifier->write($file, $mod, createBackup: false);

        $this->assertFileDoesNotExist($file.'.bak');
    }

    public function test_write_produces_file_matching_modified_code(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        $this->modifier->write($file, $mod, createBackup: false);

        $this->assertSame($mod->modifiedCode, file_get_contents($file));
    }

    public function test_restore_reads_backup_and_overwrites_modified_file(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $originalContent = (string) file_get_contents($file);

        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);
        $this->modifier->write($file, $mod, createBackup: true);
        $this->assertNotSame($originalContent, file_get_contents($file));

        $this->modifier->restore($file);

        $this->assertSame($originalContent, file_get_contents($file));
    }

    public function test_restore_throws_when_no_backup_exists(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No backup file found');

        $this->modifier->restore($file);
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../../Support/Fixtures/UserModels/'.$name.'.php';
    }

    private function copyFixtureToTmp(string $name): string
    {
        $source = $this->fixturePath($name);
        $dest = $this->tmpDir.DIRECTORY_SEPARATOR.$name.'.php';
        copy($source, $dest);
        $this->createdFiles[] = $dest;

        return $dest;
    }
}
