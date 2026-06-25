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
        $this->modifier->applyTransient($tmpFile, $mod->modifiedCode);

        require $tmpFile;

        $fqcn = "JamesGifford\\Auth\\Tests\\Tmp\\Mod{$uniqueClass}\\{$uniqueClass}";
        $this->assertTrue(class_exists($fqcn));

        $instance = new $fqcn;
        $this->assertSame('user', $instance->publicIdPrefix());
    }

    // ---- applyTransient() (transient backup) ----

    public function test_apply_transient_leaves_no_backup_after_success(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        $this->modifier->applyTransient($file, $mod->modifiedCode);

        $this->assertSame($mod->modifiedCode, file_get_contents($file));
        $this->assertFileDoesNotExist($file.'.bak', 'the transient backup must be deleted on success');
    }

    public function test_apply_transient_restores_and_cleans_up_when_edit_fails(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $original = (string) file_get_contents($file);

        // Invalid PHP fails the validity gate inside applyTransient.
        try {
            $this->modifier->applyTransient($file, '<?php this is not valid php {{{');
            $this->fail('Expected applyTransient to throw on invalid PHP.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($original, file_get_contents($file), 'the model must be restored to its pre-edit state');
        $this->assertFileDoesNotExist($file.'.bak', 'no backup may remain after a failed edit');
    }

    public function test_apply_transient_restores_when_verify_callback_throws(): void
    {
        $file = $this->copyFixtureToTmp('StandardLaravelUser');
        $original = (string) file_get_contents($file);
        $analysis = $this->modifier->analyze($file);
        $mod = $this->modifier->modify($file, $analysis);

        try {
            $this->modifier->applyTransient($file, $mod->modifiedCode, verify: function (): void {
                throw new RuntimeException('semantic check failed');
            });
            $this->fail('Expected applyTransient to rethrow the verify failure.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($original, file_get_contents($file));
        $this->assertFileDoesNotExist($file.'.bak');
    }

    // ---- reverseModify() (surgical un-install) ----

    public function test_reverse_modify_removes_only_package_additions(): void
    {
        // Fixture has HasAccounts + HasFactory + HasPublicId + Notifiable and a
        // publicIdPrefix() returning 'usr', plus fillable/hidden.
        $file = $this->copyFixtureToTmp('UserWithBothPackageTraits');
        $analysis = $this->modifier->analyze($file);

        $reversion = $this->modifier->reverseModify($file, $analysis);
        $code = $reversion->modifiedCode;

        // Package additions gone.
        $this->assertStringNotContainsString('HasPublicId', $code);
        $this->assertStringNotContainsString('HasAccounts', $code);
        $this->assertStringNotContainsString('publicIdPrefix', $code);

        // Everything else preserved.
        $this->assertStringContainsString('use HasFactory;', $code);
        $this->assertStringContainsString('use Notifiable;', $code);
        $this->assertStringContainsString('protected $fillable', $code);
        $this->assertStringContainsString('protected $hidden', $code);

        // Result metadata.
        $this->assertEqualsCanonicalizing(['HasPublicId', 'HasAccounts'], $reversion->removedTraits);
        $this->assertTrue($reversion->removedPublicIdPrefixMethod);
        $this->assertFalse($reversion->removedPrefixWasCustomized);
        $this->assertSame('usr', $reversion->removedPrefixReturnValue);
    }

    public function test_forward_then_reverse_returns_user_model_to_a_clean_state(): void
    {
        // Round-trip via the real combined `use HasPublicId, HasAccounts;` line
        // that forward modification produces.
        $file = $this->copyFixtureToTmp('StandardLaravelUser');

        $forward = $this->modifier->modify($file, $this->modifier->analyze($file));
        $this->modifier->applyTransient($file, $forward->modifiedCode);
        $afterForward = $this->modifier->analyze($file);
        $this->assertTrue($afterForward->hasHasPublicIdTrait);
        $this->assertTrue($afterForward->hasHasAccountsTrait);
        $this->assertTrue($afterForward->hasPublicIdPrefixMethod);

        $reversion = $this->modifier->reverseModify($file, $afterForward);
        $this->modifier->applyTransient($file, $reversion->modifiedCode);

        $afterReverse = $this->modifier->analyze($file);
        $this->assertFalse($afterReverse->hasHasPublicIdTrait);
        $this->assertFalse($afterReverse->hasHasAccountsTrait);
        $this->assertFalse($afterReverse->hasPublicIdPrefixMethod);

        $code = (string) file_get_contents($file);
        $this->assertStringContainsString('Notifiable', $code);
        $this->assertStringContainsString('protected $fillable', $code);
        $this->assertFileDoesNotExist($file.'.bak');
    }

    public function test_reverse_modify_flags_a_customized_prefix_method_with_logic(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Foundation\Auth\User as Authenticatable;
            use JamesGifford\Auth\Concerns\HasAccounts;
            use JamesGifford\Auth\PublicId\Concerns\HasPublicId;

            class User extends Authenticatable
            {
                use HasPublicId, HasAccounts;

                public function publicIdPrefix(): string
                {
                    return $this->is_admin ? 'adm' : 'usr';
                }
            }
            PHP;
        $file = $this->tmpDir.DIRECTORY_SEPARATOR.'CustomLogicUser.php';
        file_put_contents($file, $code);
        $this->createdFiles[] = $file;

        $reversion = $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertTrue($reversion->removedPublicIdPrefixMethod);
        $this->assertTrue($reversion->removedPrefixWasCustomized, 'a body with logic must be flagged customized');
        $this->assertNull($reversion->removedPrefixReturnValue);
        $this->assertStringNotContainsString('publicIdPrefix', $reversion->modifiedCode);
    }

    public function test_reverse_modify_throws_for_an_unmodifiable_model(): void
    {
        $file = $this->fixturePath('UserWithUnusualStructure');
        $analysis = $this->modifier->analyze($file);
        $this->assertFalse($analysis->isModifiable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot reverse-modify');

        $this->modifier->reverseModify($file, $analysis);
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
