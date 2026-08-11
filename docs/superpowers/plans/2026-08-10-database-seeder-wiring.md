# DatabaseSeeder Wiring Implementation Plan — 1.2.3

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `jamesgifford:auth:install` wires the package's two seeders into `database/seeders/DatabaseSeeder.php` so `php artisan db:seed` and `migrate:fresh --seed` seed roles everywhere and the dev cast in permitted environments; `jamesgifford:auth:uninstall` removes the package's calls and nothing else.

**Architecture:** Format-preserving AST edits via nikic/php-parser, mirroring the existing `UserModelModifier`. One modifier class, one readonly analysis object, and `?string` returns for the two edit operations. File writes go through a shared transactional writer extracted from `UserModelModifier`.

**Tech Stack:** PHP 8.4, Laravel 13, nikic/php-parser ^5, PHPUnit 11, Orchestra Testbench 11, Pint, PHPStan/Larastan level 6.

**Release:** tagged `1.2.3`. Independent of 1.2.2.

## Priorities this plan is built around

1. **Laravel/PHP idiom.** The emitted code is what a developer would have written: real `use` imports, short class names, standard spacing.
2. **`account_roles` is production data, not dev data.** The `AccountRoleSeeder` call is written **unconditionally** — independent of `--with-dev-data`, and outside the environment check. Only the dev cast is wrapped.
3. **The seeding works through artisan.** `db:seed`, `migrate:fresh --seed`, and `migrate:refresh --seed` all route through `Database\Seeders\DatabaseSeeder::run()`, so all three are covered. Verified by executing the generated code, not by asserting on strings.
4. **Uninstall removes only the package's seeders.** Verified the same way — the developer's own seeders must still run afterwards.
5. **Safe writes.** Every write is backed up, validated as parseable PHP, verified semantically, and rolled back on any failure.

## Idempotency: verified, not assumed

Re-running is safe because both seeders are idempotent at the data layer:

- `AccountRoleSeeder` (`src/Database/Seeders/AccountRoleSeeder.php:28`) uses `updateOrCreate` keyed on the role's `key`. Re-running reconciles the table to config without duplicating rows, and deliberately leaves roles that are no longer in config alone.
- `DevDataSeeder` (`src/Database/DevDataSeeder.php:174`) uses `firstOrNew` keyed on email, and explicitly handles a user row that outlived a previous uninstall.

So `db:seed` twice in a row is safe in every environment, and this plan does not need to add any guard of its own. The *wiring* is separately idempotent: `analyze()` detects an existing call and `modify()` returns null.

## Global Constraints

- **Never run `git` commands.** James stages and commits everything himself. Each task ends with a hand-off step listing files and a suggested message.
- **php-parser v5 node names:** `Node\UseItem`, `Node\ArrayItem`, `Node\Arg`, `Node\Scalar\String_`, `Node\Identifier`. (v4's `Expr\ArrayItem` does not exist here.)
- **Format preservation.** Every edit goes through `Standard::printFormatPreserving($new, $old, $oldTokens)`.
- **Fail closed.** Anything the modifier cannot handle confidently leaves the file untouched and prints instructions. A wrong edit is worse than no edit.
- **Tests never mutate shared fixtures.** Copy to a temp path, point the code at the copy, clean up. Mirrors `UserModelModifierTest`.
- **Two drivers, sequentially.** `composer test` (MariaDB, the `phpunit.xml` default) then `composer test:sqlite`. Never concurrently — they share a database name and a `TestCase` purge/disconnect step.
- **Package FQCNs**, verbatim throughout:
  - `JamesGifford\Auth\Database\Seeders\AccountRoleSeeder`
  - `JamesGifford\Auth\Database\DevDataSeeder`

## Why AST rather than a marker-comment block

A text-marker block would be smaller. AST wins on three grounds:

- **Priority 3 — the decisive one.** Removing a text span cannot distinguish the developer's statements from the package's. Node removal targets resolved class references, so a `$this->call(DemoSeeder::class)` the developer added — even inside the package's own `if` wrapper — survives uninstall.
- **Priority 1.** AST emits real imports and short class names. A marker block has to use fully-qualified names inside the block (that is how it avoids import placement) plus two marker comments, which read as vendored code in a file the developer owns.
- **Consistency.** php-parser is already a hard dependency and `UserModelModifier` already establishes "edit a consumer's file via format-preserving AST", so one technique for the job beats two.

Detection resolving class references through the import map is a consequence of using an AST at all, not extra work: it means a call is recognised by what it registers rather than by matching our exact emitted text.

Backwards compatibility is explicitly **not** a consideration. There is no upgrade path to preserve and no older wiring format to recognise.

The tradeoff being accepted: a marker block gives a byte-exact round trip. The format-preserving printer gives a semantically identical one, where blank lines around removed nodes may shift.

## The emitted code

```php
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

public function run(): void
{
    // Required account roles — every environment.
    $this->call(AccountRoleSeeder::class);

    // Development fixtures — permitted environments only. Reads the
    // `environments` key from config/jamesgifford/auth-dev.php, so adding one
    // there is all it takes. The seeder self-guards and always refuses production.
    if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {
        $this->call(DevDataSeeder::class);
    }

    // ...the developer's existing body, untouched
}
```

Prepended rather than appended: roles are a prerequisite for the dev cast and for any app seeder that creates accounts.

**The `['local', 'staging']` fallback is load-bearing, not decorative.** `ServiceProvider::mergeConfigFrom()` is skipped when `configurationIsCached()`, and `config/jamesgifford/auth-dev.php` is published only by `seed-dev-data`. In an app that ran `config:cache` before publishing it, `config('jamesgifford.auth-dev.environments')` resolves to nothing and the fallback is what keeps `db:seed` seeding the cast. Do not remove it.

---

## File Structure

**Create:**

| Path | Responsibility |
| --- | --- |
| `src/Installer/PhpFileWriter.php` | Backup → write → parse-check → verify → delete backup |
| `src/Installer/DatabaseSeederAnalysis.php` | Read-only inspection result |
| `src/Installer/SeederCallResolver.php` | Resolves what a `$this->call(...)` registers, for one file's namespace + imports |
| `src/Installer/PackageSeederRemover.php` | `NodeVisitor` that deletes the package's calls and imports, and nothing else |
| `src/Installer/DatabaseSeederModifier.php` | `analyze()` / `modify()` / `reverseModify()` / `applyTransient()` |
| `tests/Support/Fixtures/DatabaseSeeders/*.php` | 4 fixtures |
| `tests/Feature/Installer/DatabaseSeederModifierTest.php` | Modifier coverage, including executable proof |

**Modify:** `src/Installer/UserModelModifier.php`, `src/AuthServiceProvider.php`, `src/Console/Commands/AuthInstallCommand.php`, `src/Console/Commands/AuthUninstallCommand.php`, `src/Console/Commands/AuthSetupCommand.php`, four test files, `README.md`, `config/auth-dev.php`, `resources/boost/skills/jamesgifford-auth/SKILL.md`, `CHANGELOG.md`.

---

## Task 1: Extract `PhpFileWriter`

**Files:**
- Create: `src/Installer/PhpFileWriter.php`
- Modify: `src/Installer/UserModelModifier.php:37-40` (constructor), `:493-520` (`applyTransient`)
- Modify: `src/AuthServiceProvider.php:110-116`
- Modify: `tests/Feature/Installer/UserModelModifierTest.php:25`

**Interfaces:**
- Consumes: nothing.
- Produces: `PhpFileWriter::applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void` — restores the file and deletes the backup before rethrowing on any failure.

**Why now:** priority 4 means `DatabaseSeederModifier` needs the identical write transaction `UserModelModifier` already has. Duplicating it would be the wrong call, so it moves to one place first.

- [ ] **Step 1: Create the writer**

`src/Installer/PhpFileWriter.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use PhpParser\Parser;
use RuntimeException;
use Throwable;

/**
 * Transactional write for a PHP file the consumer owns.
 *
 * The file is copied to .bak before the edit, restored from it on any failure,
 * and the .bak is deleted either way — so a failed edit leaves the file exactly
 * as it was and no backup is ever orphaned.
 *
 * Shared by UserModelModifier and DatabaseSeederModifier: both edit a file the
 * package does not own, so both need the same guarantee.
 */
final class PhpFileWriter
{
    public function __construct(private readonly Parser $parser) {}

    /**
     * @param  ?Closure():void  $verify  Optional semantic check; should throw on failure.
     */
    public function applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void
    {
        $backupPath = $filePath.'.bak';
        copy($filePath, $backupPath);

        try {
            file_put_contents($filePath, $newCode);

            // Validity gate: the written file must still parse as PHP.
            $written = (string) file_get_contents($filePath);
            if ($this->parser->parse($written) === null) {
                throw new RuntimeException('the edited file did not parse as valid PHP');
            }

            if ($verify !== null) {
                $verify();
            }
        } catch (Throwable $e) {
            if (is_file($backupPath)) {
                file_put_contents($filePath, (string) file_get_contents($backupPath));
            }
            @unlink($backupPath);

            throw $e;
        }

        @unlink($backupPath);
    }
}
```

The message is generalized from "the edited User model did not parse as valid PHP". The three existing `applyTransient` tests (`UserModelModifierTest.php:288-337`) catch `RuntimeException` without asserting its text, so this is safe.

- [ ] **Step 2: Delegate from `UserModelModifier`**

Constructor (line 37):

```php
    public function __construct(
        private readonly Parser $parser,
        private readonly Standard $printer,
        private readonly PhpFileWriter $writer,
    ) {}
```

Replace the body of `applyTransient()` (lines 493-520):

```php
    /**
     * Commit new code to the model with a TRANSIENT backup. Delegates to the
     * shared {@see PhpFileWriter}; the signature is retained so existing call
     * sites and tests are unaffected.
     *
     * @param  ?Closure():void  $verify  Optional semantic check; should throw on failure.
     */
    public function applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void
    {
        $this->writer->applyTransient($filePath, $newCode, $verify);
    }
```

Run Pint at the end of the task and drop any import it flags as now-unused.

- [ ] **Step 3: Register the singleton**

In `src/AuthServiceProvider.php`, after the `PhpParserPrinter` binding (line 114) and before the `UserModelModifier` binding:

```php
        $this->app->singleton(PhpFileWriter::class);
```

Add `use JamesGifford\Auth\Installer\PhpFileWriter;` to the imports.

- [ ] **Step 4: Update the test's manual construction**

`tests/Feature/Installer/UserModelModifierTest.php:25` passes two arguments. Replace with:

```php
        $this->modifier = new UserModelModifier(
            $this->app->make(Parser::class),
            new Standard,
            $this->app->make(PhpFileWriter::class),
        );
```

Add `use JamesGifford\Auth\Installer\PhpFileWriter;` to that file's imports.

- [ ] **Step 5: Run the tests to verify nothing regressed**

```bash
vendor/bin/phpunit tests/Feature/Installer/
```

Expected: PASS. This task is a pure refactor with no behavior change.

- [ ] **Step 6: Hand off (do not run git)**

Files ready: `src/Installer/PhpFileWriter.php`, `src/Installer/UserModelModifier.php`, `src/AuthServiceProvider.php`, `tests/Feature/Installer/UserModelModifierTest.php`.

Suggested message: `Extract PhpFileWriter from UserModelModifier`

---

## Task 2: `DatabaseSeederModifier`

**Files:**
- Create: `tests/Support/Fixtures/DatabaseSeeders/` (4 files)
- Create: `src/Installer/DatabaseSeederAnalysis.php`
- Create: `src/Installer/SeederCallResolver.php`
- Create: `src/Installer/PackageSeederRemover.php`
- Create: `src/Installer/DatabaseSeederModifier.php`
- Create: `tests/Feature/Installer/DatabaseSeederModifierTest.php`
- Modify: `src/AuthServiceProvider.php`

**Interfaces:**
- Consumes: `PhpFileWriter` (Task 1).
- Produces:
  - `DatabaseSeederModifier::ACCOUNT_ROLE_SEEDER` / `::DEV_DATA_SEEDER` (string constants)
  - `analyze(string $filePath): DatabaseSeederAnalysis`
  - `modify(string $filePath, DatabaseSeederAnalysis $analysis): ?string` — new code, or null when not modifiable or already wired
  - `reverseModify(string $filePath, DatabaseSeederAnalysis $analysis): ?string` — new code, or null when nothing to remove
  - `applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void`
  - `DatabaseSeederAnalysis` — public readonly `fileExists`, `parseable`, `namespace`, `hasRunMethod`, `hasAccountRoleSeederCall`, `hasDevDataSeederCall`, `shortNamesAvailable`, `unusualReason`; methods `needsWiring(): bool`, `isWired(): bool`, `isModifiable(): bool`, `isReversible(): bool`
  - `SeederCallResolver::__construct(?string $namespace, array $importMap)`; `targetsOf(MethodCall): list<string>`, `packageTargetsOf(MethodCall): list<string>`, `isPackageSeederReference(ClassConstFetch): bool`, `resolve(ClassConstFetch): ?string`
  - `PackageSeederRemover::__construct(SeederCallResolver)` — a `NodeVisitorAbstract`

- [ ] **Step 1: Create the fixtures**

`tests/Support/Fixtures/DatabaseSeeders/StockSeeder.php` — the Laravel skeleton, the overwhelmingly common case:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // User::factory(10)->create();
    }
}
```

`AlreadyWiredSeeder.php` — wired, but with a hardcoded environment check rather than the config-reading one this package emits. Detection must recognise it from what the calls *register*, not from matching our own output text, or a developer who adjusted the wiring gets it duplicated on the next install:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use JamesGifford\Auth\Database\DevDataSeeder;
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccountRoleSeeder::class);

        if (app()->environment('local', 'staging')) {
            $this->call(DevDataSeeder::class);
        }
    }
}
```

`ArrayCallSeeder.php` — the other idiomatic registration form, fully qualified:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class,
            \JamesGifford\Auth\Database\DevDataSeeder::class,
        ]);
    }
}
```

`NoRunMethodSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function somethingElse(): void
    {
        //
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Installer/DatabaseSeederModifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JamesGifford\Auth\Installer\DatabaseSeederModifier;
use JamesGifford\Auth\SystemRole;
use JamesGifford\Auth\Tests\TestCase;

class DatabaseSeederModifierTest extends TestCase
{
    private DatabaseSeederModifier $modifier;

    private string $tmpDir;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->modifier = $this->app->make(DatabaseSeederModifier::class);
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jamesgifford-seeder-'.uniqid('', true);
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

    public function test_analyze_identifies_the_stock_seeder_as_modifiable_and_unwired(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('StockSeeder'));

        $this->assertTrue($analysis->isModifiable());
        $this->assertTrue($analysis->hasRunMethod);
        $this->assertTrue($analysis->needsWiring());
        $this->assertFalse($analysis->isWired());
    }

    public function test_analyze_recognises_wiring_that_does_not_match_our_emitted_text(): void
    {
        // Wired with a hardcoded env check instead of the config-reading one we
        // emit. Detection is semantic, so this must NOT be wired a second time.
        $analysis = $this->modifier->analyze($this->fixturePath('AlreadyWiredSeeder'));

        $this->assertTrue($analysis->hasAccountRoleSeederCall);
        $this->assertTrue($analysis->hasDevDataSeederCall);
        $this->assertFalse($analysis->needsWiring());
    }

    public function test_analyze_recognises_the_array_call_form(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('ArrayCallSeeder'));

        $this->assertTrue($analysis->hasAccountRoleSeederCall);
        $this->assertTrue($analysis->hasDevDataSeederCall);
        $this->assertFalse($analysis->needsWiring());
    }

    public function test_analyze_rejects_a_seeder_without_a_run_method(): void
    {
        $analysis = $this->modifier->analyze($this->fixturePath('NoRunMethodSeeder'));

        $this->assertFalse($analysis->hasRunMethod);
        $this->assertFalse($analysis->isModifiable());
        $this->assertSame('no run() method with a body was found', $analysis->unusualReason);
    }

    public function test_analyze_fails_closed_on_a_short_name_collision(): void
    {
        $file = $this->writeTmp('Collision', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use App\Seeders\AccountRoleSeeder;
            use Illuminate\Database\Seeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    $this->call(AccountRoleSeeder::class);
                }
            }
            PHP);

        $analysis = $this->modifier->analyze($file);

        // That AccountRoleSeeder is App\Seeders\, not ours.
        $this->assertFalse($analysis->hasAccountRoleSeederCall);
        $this->assertFalse($analysis->shortNamesAvailable);
        $this->assertFalse($analysis->isModifiable());
    }

    public function test_analyze_reports_a_missing_file(): void
    {
        $analysis = $this->modifier->analyze($this->tmpDir.DIRECTORY_SEPARATOR.'Nope.php');

        $this->assertFalse($analysis->fileExists);
        $this->assertFalse($analysis->isModifiable());
    }

    // ---- modify() ----

    public function test_modify_emits_idiomatic_imports_and_short_names(): void
    {
        $file = $this->copyFixtureToTmp('StockSeeder');
        $code = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;', $code);
        $this->assertStringContainsString('use JamesGifford\Auth\Database\DevDataSeeder;', $code);
        $this->assertStringContainsString('$this->call(AccountRoleSeeder::class);', $code);
        $this->assertStringContainsString('$this->call(DevDataSeeder::class);', $code);
        $this->assertStringContainsString('jamesgifford.auth-dev.environments', $code);
    }

    public function test_modify_preserves_the_existing_body(): void
    {
        $file = $this->copyFixtureToTmp('StockSeeder');
        $code = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('// User::factory(10)->create();', $code);
    }

    public function test_modify_puts_roles_before_the_dev_cast(): void
    {
        $file = $this->copyFixtureToTmp('StockSeeder');
        $code = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->assertLessThan(
            strpos($code, '$this->call(DevDataSeeder::class);'),
            strpos($code, '$this->call(AccountRoleSeeder::class);'),
            'roles are a prerequisite for the dev cast',
        );
    }

    public function test_modify_emits_readably_spaced_code(): void
    {
        // Priority 1. Comment rendering and blank-line placement on NEW nodes
        // is the one thing printFormatPreserving does not do for free, and
        // UserModelModifier never exercised it — so pin the real output.
        $file = $this->copyFixtureToTmp('StockSeeder');
        $code = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString(
            "        // Required account roles — every environment.\n"
                ."        \$this->call(AccountRoleSeeder::class);\n",
            $code,
            'the role call must carry its explanatory comment',
        );

        $this->assertStringContainsString(
            "\$this->call(AccountRoleSeeder::class);\n\n        // Development fixtures",
            $code,
            'a blank line must separate the role call from the dev block',
        );
    }

    public function test_modify_is_idempotent(): void
    {
        $file = $this->copyFixtureToTmp('StockSeeder');
        $this->modifier->applyTransient($file, (string) $this->modifier->modify($file, $this->modifier->analyze($file)));

        $this->assertFalse($this->modifier->analyze($file)->needsWiring());
        $this->assertNull($this->modifier->modify($file, $this->modifier->analyze($file)));
    }

    public function test_modify_returns_null_for_an_unmodifiable_seeder(): void
    {
        $file = $this->fixturePath('NoRunMethodSeeder');

        $this->assertNull($this->modifier->modify($file, $this->modifier->analyze($file)));
    }

    // ---- Priority 2: the generated code actually seeds ----

    public function test_generated_seeder_seeds_the_dev_cast_when_executed(): void
    {
        $this->app['env'] = 'local'; // dev-data allowlisted
        $this->seedRolesForDevData();

        $file = $this->copyFixtureToTmp('StockSeeder');
        $wired = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $seeder = $this->instantiate($wired, 'GeneratedSeeder');
        $seeder->run();

        $this->assertDatabaseHas('users', ['email' => 'owner@dev.test']);
        $this->assertDatabaseHas('accounts', ['name' => 'Acme Inc']);
    }

    public function test_generated_seeder_seeds_roles_outside_permitted_environments(): void
    {
        // account_roles is production data, not dev data: the role call sits
        // OUTSIDE the environment check and must run everywhere.
        $this->app['env'] = 'production';

        $file = $this->copyFixtureToTmp('StockSeeder');
        $wired = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->instantiate($wired, 'ProdRolesSeeder')->run();

        $this->assertDatabaseHas('account_roles', ['key' => SystemRole::OWNER]);
    }

    public function test_generated_seeder_is_safe_to_run_twice(): void
    {
        // Both seeders are idempotent at the data layer (updateOrCreate on the
        // role key, firstOrNew on the user email), so db:seed twice must not
        // duplicate anything.
        $this->app['env'] = 'local';
        $this->seedRolesForDevData();

        $file = $this->copyFixtureToTmp('StockSeeder');
        $wired = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $seeder = $this->instantiate($wired, 'TwiceSeeder');
        $seeder->run();
        $seeder->run();

        $this->assertSame(1, DB::table('users')->where('email', 'owner@dev.test')->count());
        $this->assertSame(1, DB::table('accounts')->where('name', 'Acme Inc')->count());
        $this->assertSame(1, DB::table('account_roles')->where('key', SystemRole::OWNER)->count());
    }

    public function test_generated_seeder_skips_the_dev_cast_outside_permitted_environments(): void
    {
        $this->app['env'] = 'production';
        $this->seedRolesForDevData();

        $file = $this->copyFixtureToTmp('StockSeeder');
        $wired = (string) $this->modifier->modify($file, $this->modifier->analyze($file));

        $this->instantiate($wired, 'ProdSeeder')->run();

        $this->assertDatabaseMissing('users', ['email' => 'owner@dev.test']);
    }

    // ---- reverseModify(), and Priority 3 ----

    public function test_reverse_modify_removes_the_package_calls_and_imports(): void
    {
        $file = $this->copyFixtureToTmp('AlreadyWiredSeeder');
        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringNotContainsString('AccountRoleSeeder', $code);
        $this->assertStringNotContainsString('DevDataSeeder', $code);
    }

    public function test_reverse_modify_keeps_the_developers_own_seeders(): void
    {
        $file = $this->writeTmp('WithAppSeeder', <<<'PHP'
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

        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('$this->call(ProductSeeder::class);', $code);
        $this->assertStringNotContainsString('AccountRoleSeeder', $code);
    }

    public function test_reverse_modify_keeps_a_condition_that_held_other_statements(): void
    {
        $file = $this->writeTmp('SharedCondition', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Illuminate\Database\Seeder;
            use JamesGifford\Auth\Database\DevDataSeeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    if (app()->environment('local')) {
                        $this->call(DevDataSeeder::class);
                        $this->call(DemoSeeder::class);
                    }
                }
            }
            PHP);

        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('app()->environment', $code);
        $this->assertStringContainsString('DemoSeeder', $code);
        $this->assertStringNotContainsString('DevDataSeeder', $code);
    }

    public function test_reverse_modify_leaves_an_already_empty_condition_alone(): void
    {
        // The developer's own empty if is not ours to delete, even though the
        // pass leaves nothing behind in the sibling condition.
        $file = $this->writeTmp('EmptyCondition', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Illuminate\Database\Seeder;
            use JamesGifford\Auth\Database\DevDataSeeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    if ($this->somethingPending()) {
                    }

                    if (app()->environment('local')) {
                        $this->call(DevDataSeeder::class);
                    }
                }
            }
            PHP);

        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('$this->somethingPending()', $code);
        $this->assertStringNotContainsString('DevDataSeeder', $code);
        $this->assertStringNotContainsString("app()->environment('local')", $code);
    }

    public function test_reverse_modify_keeps_a_condition_whose_array_call_survives(): void
    {
        // The single statement is filtered rather than removed, so the
        // condition still has a body and must stay.
        $file = $this->writeTmp('ArrayInCondition', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Illuminate\Database\Seeder;
            use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    if (app()->environment('local')) {
                        $this->call([AccountRoleSeeder::class, ProductSeeder::class]);
                    }
                }
            }
            PHP);

        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('app()->environment', $code);
        $this->assertStringContainsString('ProductSeeder::class', $code);
        $this->assertStringNotContainsString('AccountRoleSeeder', $code);
    }

    public function test_reverse_modify_strips_only_package_entries_from_an_array_call(): void
    {
        $file = $this->writeTmp('ArrayMixed', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Illuminate\Database\Seeder;
            use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    $this->call([AccountRoleSeeder::class, ProductSeeder::class]);
                }
            }
            PHP);

        $code = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        $this->assertStringContainsString('ProductSeeder::class', $code);
        $this->assertStringNotContainsString('AccountRoleSeeder', $code);
    }

    public function test_reverted_seeder_still_runs_the_developers_seeder(): void
    {
        // Priority 3, proven by execution: after uninstall the file must still
        // work, and must still invoke what the developer put there.
        $this->app['env'] = 'local';
        $this->seedRolesForDevData();

        $file = $this->writeTmp('RevertExec', <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Illuminate\Database\Seeder;
            use JamesGifford\Auth\Database\DevDataSeeder;
            use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

            class DatabaseSeeder extends Seeder
            {
                public function run(): void
                {
                    $this->call(AccountRoleSeeder::class);

                    if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {
                        $this->call(DevDataSeeder::class);
                    }

                    \JamesGifford\Auth\Tests\Feature\Installer\SeederSpy::$ran = true;
                }
            }
            PHP);

        $reverted = (string) $this->modifier->reverseModify($file, $this->modifier->analyze($file));

        SeederSpy::$ran = false;
        $this->instantiate($reverted, 'RevertedSeeder')->run();

        $this->assertTrue(SeederSpy::$ran, "the developer's own code must still run");
        $this->assertDatabaseMissing('users', ['email' => 'owner@dev.test']);
    }

    // ---- helpers ----

    /**
     * Rename the class, write it to a unique file, require it, and return an
     * instance with the container attached. Renaming avoids redeclaration
     * across tests in one PHP process; the modifier never depends on the class
     * name, only on run(), so this is faithful.
     */
    private function instantiate(string $code, string $className): Seeder
    {
        $code = (string) preg_replace('/\bclass\s+DatabaseSeeder\b/', 'class '.$className, $code, 1);
        $file = $this->tmpDir.DIRECTORY_SEPARATOR.$className.'.php';
        file_put_contents($file, $code);
        $this->createdFiles[] = $file;

        require $file;

        $fqcn = 'Database\\Seeders\\'.$className;
        /** @var Seeder $seeder */
        $seeder = new $fqcn;
        $seeder->setContainer($this->app);

        return $seeder;
    }

    /**
     * DevDataSeeder assigns roles, so account_roles must be populated first.
     */
    private function seedRolesForDevData(): void
    {
        $this->seed(\JamesGifford\Auth\Database\Seeders\AccountRoleSeeder::class);
    }

    private function fixturePath(string $name): string
    {
        return __DIR__.'/../../Support/Fixtures/DatabaseSeeders/'.$name.'.php';
    }

    private function copyFixtureToTmp(string $name): string
    {
        $dest = $this->tmpDir.DIRECTORY_SEPARATOR.$name.'.php';
        copy($this->fixturePath($name), $dest);
        $this->createdFiles[] = $dest;

        return $dest;
    }

    private function writeTmp(string $name, string $code): string
    {
        $file = $this->tmpDir.DIRECTORY_SEPARATOR.$name.'.php';
        file_put_contents($file, $code);
        $this->createdFiles[] = $file;

        return $file;
    }
}

/** Records that the developer's own statement executed after reversion. */
class SeederSpy
{
    public static bool $ran = false;
}
```

If the fixture classes collide on `Database\Seeders\DatabaseSeeder` when required, note that only `instantiate()` requires anything — the fixtures are read as text by the modifier, never loaded.

- [ ] **Step 3: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/Installer/DatabaseSeederModifierTest.php
```

Expected: FAIL — `Class "JamesGifford\Auth\Installer\DatabaseSeederModifier" not found`.

- [ ] **Step 4: Create `DatabaseSeederAnalysis`**

`src/Installer/DatabaseSeederAnalysis.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

/**
 * The result of inspecting a consumer's database/seeders/DatabaseSeeder.php
 * without modifying it.
 *
 * `shortNamesAvailable` is false when the file already imports a DIFFERENT
 * class under either package short name. Rather than emitting a fully-qualified
 * call as a fallback — two output shapes to maintain and test — the analysis
 * simply reports the file as unmodifiable and the command prints instructions.
 * The case is vanishingly rare and failing closed is cheaper than a dual mode.
 */
final readonly class DatabaseSeederAnalysis
{
    public function __construct(
        public bool $fileExists,
        public bool $parseable,
        public ?string $namespace,
        public bool $hasRunMethod,
        public bool $hasAccountRoleSeederCall,
        public bool $hasDevDataSeederCall,
        public bool $shortNamesAvailable,
        public ?string $unusualReason,
    ) {}

    public function isWired(): bool
    {
        return $this->hasAccountRoleSeederCall && $this->hasDevDataSeederCall;
    }

    public function needsWiring(): bool
    {
        return ! $this->isWired();
    }

    /**
     * Safe to ADD wiring to. Requires the short names to be free, because the
     * forward edit emits imports.
     */
    public function isModifiable(): bool
    {
        return $this->isStructurallySound() && $this->shortNamesAvailable;
    }

    /**
     * Safe to REMOVE wiring from. Deliberately does NOT require
     * `shortNamesAvailable`: reversion emits no imports, so a colliding short
     * name is irrelevant to it. Requiring it would leave a file the package
     * could detect its calls in but refuse to clean up.
     */
    public function isReversible(): bool
    {
        return $this->isStructurallySound();
    }

    private function isStructurallySound(): bool
    {
        return $this->fileExists
            && $this->parseable
            && $this->hasRunMethod
            && $this->unusualReason === null;
    }
}
```

- [ ] **Step 5: Create `SeederCallResolver` and `PackageSeederRemover`**

These exist so `DatabaseSeederModifier` needs no public methods that only a visitor calls. An anonymous inner class reaching back into its host through `public` helpers is the kind of encapsulation leak a reviewer should reject; two named collaborators is ordinary PHP.

`src/Installer/SeederCallResolver.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use PhpParser\Node;
use PhpParser\Node\Name;

/**
 * Resolves what a `$this->call(...)` registers, in the context of one file's
 * namespace and import map.
 *
 * Shared by DatabaseSeederModifier::analyze() and PackageSeederRemover so
 * "which class does this reference mean" has exactly one implementation.
 */
final readonly class SeederCallResolver
{
    /** Seeder methods that register another seeder. */
    private const CALL_METHODS = ['call', 'callonce', 'callwith'];

    private const PACKAGE_SEEDERS = [
        DatabaseSeederModifier::ACCOUNT_ROLE_SEEDER,
        DatabaseSeederModifier::DEV_DATA_SEEDER,
    ];

    /**
     * @param  array<string, string>  $importMap  short name => FQCN
     */
    public function __construct(
        private ?string $namespace,
        private array $importMap,
    ) {}

    /**
     * The class names a single `$this->call(...)` registers — one for the
     * scalar form, many for the array form. Empty for anything that is not a
     * seeder registration.
     *
     * @return list<string>
     */
    public function targetsOf(Node\Expr\MethodCall $call): array
    {
        if (! $call->var instanceof Node\Expr\Variable || $call->var->name !== 'this') {
            return [];
        }

        if (! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toLowerString(), self::CALL_METHODS, true)
        ) {
            return [];
        }

        $first = $call->args[0] ?? null;
        if (! $first instanceof Node\Arg) {
            return [];
        }

        $expr = $first->value;

        if ($expr instanceof Node\Expr\ClassConstFetch) {
            $fqcn = $this->resolve($expr);

            return $fqcn === null ? [] : [$fqcn];
        }

        if ($expr instanceof Node\Expr\Array_) {
            $fqcns = [];
            foreach ($expr->items as $item) {
                if ($item instanceof Node\ArrayItem && $item->value instanceof Node\Expr\ClassConstFetch) {
                    $fqcn = $this->resolve($item->value);
                    if ($fqcn !== null) {
                        $fqcns[] = $fqcn;
                    }
                }
            }

            return $fqcns;
        }

        return [];
    }

    /**
     * Package seeders only.
     *
     * @return list<string>
     */
    public function packageTargetsOf(Node\Expr\MethodCall $call): array
    {
        return array_values(array_filter(
            $this->targetsOf($call),
            static fn (string $fqcn): bool => in_array($fqcn, self::PACKAGE_SEEDERS, true),
        ));
    }

    public function isPackageSeederReference(Node\Expr\ClassConstFetch $fetch): bool
    {
        return in_array($this->resolve($fetch), self::PACKAGE_SEEDERS, true);
    }

    /**
     * `Foo::class` => the FQCN it denotes here, or null if it is not a ::class
     * constant fetch on a resolvable name.
     */
    public function resolve(Node\Expr\ClassConstFetch $fetch): ?string
    {
        if (! $fetch->class instanceof Name) {
            return null;
        }

        if (! $fetch->name instanceof Node\Identifier || $fetch->name->toLowerString() !== 'class') {
            return null;
        }

        $name = $fetch->class;

        if ($name->isFullyQualified() || count($name->getParts()) > 1) {
            return $name->toString();
        }

        $short = $name->getLast();

        return $this->importMap[$short] ?? ($this->namespace !== null ? $this->namespace.'\\'.$short : $short);
    }
}
```

`src/Installer/PackageSeederRemover.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SplObjectStorage;

/**
 * Removes ONLY the package's seeder imports and calls.
 *
 *  - A call registering nothing but package seeders is removed outright.
 *  - An array-form call keeps every non-package entry.
 *  - An enclosing `if` is removed only when it wrapped nothing but calls this
 *    pass removes outright, and carries no else/elseif.
 *
 * The condition decision is made on the way DOWN, in enterNode. Checking for an
 * empty body on the way up would also delete an `if` the developer had already
 * left empty, and would mis-handle a condition whose only statement is an
 * array-form call that survives filtering.
 */
final class PackageSeederRemover extends NodeVisitorAbstract
{
    /** @var SplObjectStorage<Stmt\If_, null> */
    private SplObjectStorage $conditionsToDrop;

    public function __construct(private readonly SeederCallResolver $resolver)
    {
        $this->conditionsToDrop = new SplObjectStorage;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\If_ && $this->wrapsOnlyRemovableCalls($node)) {
            $this->conditionsToDrop->attach($node);
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|null
    {
        if ($node instanceof Stmt\Use_) {
            return $this->pruneImports($node);
        }

        if ($node instanceof Stmt\Expression) {
            return $this->pruneCall($node);
        }

        if ($node instanceof Stmt\If_) {
            return $this->conditionsToDrop->contains($node)
                ? NodeTraverser::REMOVE_NODE
                : null;
        }

        return null;
    }

    private function pruneImports(Stmt\Use_ $node): int|Node
    {
        $kept = array_values(array_filter(
            $node->uses,
            static fn (Node\UseItem $item): bool => ! in_array($item->name->toString(), [
                DatabaseSeederModifier::ACCOUNT_ROLE_SEEDER,
                DatabaseSeederModifier::DEV_DATA_SEEDER,
            ], true),
        ));

        if ($kept === []) {
            return NodeTraverser::REMOVE_NODE;
        }

        $node->uses = $kept;

        return $node;
    }

    private function pruneCall(Stmt\Expression $node): int|Node|null
    {
        $expr = $node->expr;
        if (! $expr instanceof Node\Expr\MethodCall) {
            return null;
        }

        if ($this->resolver->packageTargetsOf($expr) === []) {
            return null;
        }

        $first = $expr->args[0] ?? null;

        // Array form: drop only the package entries, keep the rest.
        if ($first instanceof Node\Arg && $first->value instanceof Node\Expr\Array_) {
            $kept = array_values(array_filter(
                $first->value->items,
                fn (?Node\ArrayItem $item): bool => ! $item instanceof Node\ArrayItem
                    || ! $item->value instanceof Node\Expr\ClassConstFetch
                    || ! $this->resolver->isPackageSeederReference($item->value),
            ));

            if ($kept === []) {
                return NodeTraverser::REMOVE_NODE;
            }

            $first->value->items = $kept;

            return $node;
        }

        return NodeTraverser::REMOVE_NODE;
    }

    private function wrapsOnlyRemovableCalls(Stmt\If_ $node): bool
    {
        if ($node->stmts === [] || $node->elseifs !== [] || $node->else !== null) {
            return false;
        }

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Stmt\Expression || ! $this->willBeRemovedEntirely($stmt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether pruneCall() will delete this statement outright, as opposed to
     * leaving a filtered array call behind.
     */
    private function willBeRemovedEntirely(Stmt\Expression $stmt): bool
    {
        $expr = $stmt->expr;
        if (! $expr instanceof Node\Expr\MethodCall) {
            return false;
        }

        if ($this->resolver->packageTargetsOf($expr) === []) {
            return false;
        }

        $first = $expr->args[0] ?? null;
        if (! $first instanceof Node\Arg || ! $first->value instanceof Node\Expr\Array_) {
            return true; // Scalar form — the whole statement goes.
        }

        foreach ($first->value->items as $item) {
            if (! $item instanceof Node\ArrayItem
                || ! $item->value instanceof Node\Expr\ClassConstFetch
                || ! $this->resolver->isPackageSeederReference($item->value)
            ) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 6: Create `DatabaseSeederModifier`**

`src/Installer/DatabaseSeederModifier.php`:

```php
<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Closure;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

/**
 * AST-based modifier for the consumer's database/seeders/DatabaseSeeder.php.
 *
 * Mirrors UserModelModifier: analyze() inspects, modify() plans the forward
 * install edit, reverseModify() plans the surgical uninstall removal, and
 * applyTransient() commits through the shared PhpFileWriter. The
 * format-preserving printer keeps everything the package did not touch byte
 * identical.
 *
 * Detection resolves class references through the file's import map, so a
 * developer who wired these seeders BY HAND — as README.md instructed before
 * this was automated — is recognised and never wired a second time.
 */
final class DatabaseSeederModifier
{
    public const ACCOUNT_ROLE_SEEDER = 'JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder';

    public const DEV_DATA_SEEDER = 'JamesGifford\\Auth\\Database\\DevDataSeeder';

    public function __construct(
        private readonly Parser $parser,
        private readonly Standard $printer,
        private readonly PhpFileWriter $writer,
    ) {}

    public function analyze(string $filePath): DatabaseSeederAnalysis
    {
        if (! file_exists($filePath)) {
            return $this->unusable('file does not exist', fileExists: false);
        }

        try {
            $ast = $this->parser->parse((string) file_get_contents($filePath));
        } catch (Throwable) {
            return $this->unusable('file is not parseable PHP', parseable: false);
        }

        if ($ast === null) {
            return $this->unusable('file is not parseable PHP', parseable: false);
        }

        [$namespace, $importMap, $classNodes] = $this->resolveContext($ast);

        if (count($classNodes) !== 1) {
            return $this->unusable(count($classNodes) === 0
                ? 'no class declaration found in the file'
                : 'multiple class declarations found in a single file');
        }

        $runMethod = $this->findRunMethod($classNodes[0]);

        if ($runMethod === null) {
            return $this->unusable('no run() method with a body was found');
        }

        $called = $this->calledSeeders($runMethod, new SeederCallResolver($namespace, $importMap));

        return new DatabaseSeederAnalysis(
            fileExists: true,
            parseable: true,
            namespace: $namespace,
            hasRunMethod: true,
            hasAccountRoleSeederCall: in_array(self::ACCOUNT_ROLE_SEEDER, $called, true),
            hasDevDataSeederCall: in_array(self::DEV_DATA_SEEDER, $called, true),
            shortNamesAvailable: $this->shortNameFree($importMap, 'AccountRoleSeeder', self::ACCOUNT_ROLE_SEEDER)
                && $this->shortNameFree($importMap, 'DevDataSeeder', self::DEV_DATA_SEEDER),
            unusualReason: null,
        );
    }

    /**
     * Plan the forward edit. Returns null when the file cannot be safely edited
     * or is already fully wired.
     */
    public function modify(string $filePath, DatabaseSeederAnalysis $analysis): ?string
    {
        if (! $analysis->isModifiable() || ! $analysis->needsWiring()) {
            return null;
        }

        $originalCode = (string) file_get_contents($filePath);
        $oldStmts = $this->parser->parse($originalCode);
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        /** @var array<int, Stmt> $newStmts */
        $newStmts = $traverser->traverse($oldStmts);

        $imports = [];
        $prepend = [];

        if (! $analysis->hasAccountRoleSeederCall) {
            $imports[] = self::ACCOUNT_ROLE_SEEDER;
            $prepend[] = $this->buildRoleCall();
        }

        if (! $analysis->hasDevDataSeederCall) {
            $imports[] = self::DEV_DATA_SEEDER;
            $prepend[] = $this->buildDevBlock();
        }

        $newStmts = $this->applyForwardEdit($newStmts, $imports, $prepend);

        return $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }

    /**
     * Plan the surgical removal. Returns null when there is nothing of the
     * package's left to remove, or the file cannot be safely edited.
     */
    public function reverseModify(string $filePath, DatabaseSeederAnalysis $analysis): ?string
    {
        if (! $analysis->isReversible()) {
            return null;
        }

        if (! $analysis->hasAccountRoleSeederCall && ! $analysis->hasDevDataSeederCall) {
            return null;
        }

        $originalCode = (string) file_get_contents($filePath);
        $oldStmts = $this->parser->parse($originalCode);
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);
        /** @var array<int, Stmt> $newStmts */
        $newStmts = $traverser->traverse($oldStmts);

        [, $importMap] = $this->resolveContext($newStmts);

        $removalTraverser = new NodeTraverser;
        $removalTraverser->addVisitor(new PackageSeederRemover(
            new SeederCallResolver($analysis->namespace, $importMap),
        ));
        $newStmts = $removalTraverser->traverse($newStmts);

        return $this->printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }

    /**
     * Commit new code with a transient backup, via the shared writer.
     *
     * @param  ?Closure():void  $verify  Optional semantic check; should throw on failure.
     */
    public function applyTransient(string $filePath, string $newCode, ?Closure $verify = null): void
    {
        $this->writer->applyTransient($filePath, $newCode, $verify);
    }

    // ---- Forward edit ----

    private function buildRoleCall(): Stmt\Expression
    {
        $stmt = new Stmt\Expression($this->buildCall('AccountRoleSeeder'));
        $stmt->setAttribute('comments', [
            new Comment('// Required account roles — every environment.'),
        ]);

        return $stmt;
    }

    /**
     * The dev-fixture call, wrapped in an environment check that READS the
     * package config rather than hardcoding local/staging — a hardcoded list
     * would be a second copy of the allowlist and would silently block any
     * environment added there later.
     *
     * The inline ['local', 'staging'] default is load-bearing: mergeConfigFrom
     * is skipped when the app's config is cached, and auth-dev.php is published
     * only by seed-dev-data, so the key can legitimately be absent.
     */
    private function buildDevBlock(): Stmt\If_
    {
        $condition = new Node\Expr\MethodCall(
            new Node\Expr\FuncCall(new Name('app')),
            'environment',
            [new Node\Arg(new Node\Expr\FuncCall(new Name('config'), [
                new Node\Arg(new Node\Scalar\String_('jamesgifford.auth-dev.environments')),
                new Node\Arg(new Node\Expr\Array_([
                    new Node\ArrayItem(new Node\Scalar\String_('local')),
                    new Node\ArrayItem(new Node\Scalar\String_('staging')),
                ], ['kind' => Node\Expr\Array_::KIND_SHORT])),
            ]))],
        );

        $if = new Stmt\If_($condition, [
            'stmts' => [new Stmt\Expression($this->buildCall('DevDataSeeder'))],
        ]);

        // The leading "\n" is the php-parser idiom for a blank line before a
        // node: the pretty printer writes comment text verbatim. Without it the
        // role call and this block run together with no separation, which is
        // not how a developer would have written it.
        $if->setAttribute('comments', [
            new Comment("\n".'// Development fixtures — permitted environments only. Reads the'),
            new Comment('// `environments` key from config/jamesgifford/auth-dev.php, so adding one'),
            new Comment('// there is all it takes. The seeder self-guards and always refuses production.'),
        ]);

        return $if;
    }

    private function buildCall(string $shortName): Node\Expr\MethodCall
    {
        return new Node\Expr\MethodCall(
            new Node\Expr\Variable('this'),
            'call',
            [new Node\Arg(new Node\Expr\ClassConstFetch(new Name($shortName), 'class'))],
        );
    }

    /**
     * Insert imports after the last existing use statement, and prepend the new
     * statements to the top of run().
     *
     * @param  array<int, Stmt>  $stmts
     * @param  list<string>  $imports
     * @param  list<Stmt>  $prepend
     * @return array<int, Stmt>
     */
    private function applyForwardEdit(array $stmts, array $imports, array $prepend): array
    {
        $container = null;
        foreach ($stmts as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $container = $top;
                break;
            }
        }

        $body = $container instanceof Stmt\Namespace_ ? $container->stmts : $stmts;

        $lastUseIndex = -1;
        foreach ($body as $i => $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                $lastUseIndex = $i;
            }
        }

        $newUses = array_map(
            static fn (string $fqcn): Stmt\Use_ => new Stmt\Use_([new Node\UseItem(new Name($fqcn))]),
            $imports,
        );

        if ($newUses !== []) {
            if ($lastUseIndex === -1) {
                $body = array_merge($newUses, $body);
            } else {
                array_splice($body, $lastUseIndex + 1, 0, $newUses);
            }
        }

        foreach ($body as $stmt) {
            if (! $stmt instanceof Stmt\Class_) {
                continue;
            }
            foreach ($stmt->stmts as $bodyStmt) {
                if ($bodyStmt instanceof Stmt\ClassMethod
                    && $bodyStmt->name->toString() === 'run'
                    && $bodyStmt->stmts !== null
                ) {
                    $bodyStmt->stmts = array_merge($prepend, $bodyStmt->stmts);
                }
            }
        }

        if ($container instanceof Stmt\Namespace_) {
            $container->stmts = $body;

            return $stmts;
        }

        return $body;
    }

    // ---- Internals ----

    /**
     * Every package seeder registered anywhere inside run(). NodeFinder recurses
     * by default, so a call wrapped in an if/foreach/try is found exactly like a
     * top-level one — which is what makes re-running the installer idempotent
     * against the wrapped form it writes itself.
     *
     * @return list<string>
     */
    private function calledSeeders(Stmt\ClassMethod $runMethod, SeederCallResolver $resolver): array
    {
        $found = [];

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($runMethod->stmts ?? [], Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            foreach ($resolver->targetsOf($call) as $fqcn) {
                $found[] = $fqcn;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $importMap
     */
    private function shortNameFree(array $importMap, string $short, string $fqcn): bool
    {
        return ! isset($importMap[$short]) || $importMap[$short] === $fqcn;
    }

    private function findRunMethod(Stmt\Class_ $classNode): ?Stmt\ClassMethod
    {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod
                && $stmt->name->toString() === 'run'
                && $stmt->stmts !== null
            ) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * @param  array<int, Stmt>  $ast
     * @return array{0: ?string, 1: array<string, string>, 2: list<Stmt\Class_>}
     */
    private function resolveContext(array $ast): array
    {
        $namespace = null;
        $importMap = [];
        $classNodes = [];
        $scan = $ast;

        foreach ($ast as $top) {
            if ($top instanceof Stmt\Namespace_) {
                $namespace = $top->name?->toString();
                $scan = $top->stmts;
                break;
            }
        }

        foreach ($scan as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $useItem) {
                    $short = $useItem->alias?->toString() ?? $useItem->name->getLast();
                    $importMap[$short] = $useItem->name->toString();
                }
            } elseif ($stmt instanceof Stmt\Class_) {
                $classNodes[] = $stmt;
            }
        }

        return [$namespace, $importMap, $classNodes];
    }

    private function unusable(string $reason, bool $fileExists = true, bool $parseable = true): DatabaseSeederAnalysis
    {
        return new DatabaseSeederAnalysis(
            fileExists: $fileExists,
            parseable: $parseable,
            namespace: null,
            hasRunMethod: false,
            hasAccountRoleSeederCall: false,
            hasDevDataSeederCall: false,
            shortNamesAvailable: true,
            unusualReason: $reason,
        );
    }
}
```

Note `shortNamesAvailable` is set on the happy path only; `unusable()` leaves it `true` because `isModifiable()` already fails on `unusualReason`. The collision case sets it false with `unusualReason` null, which is why `isModifiable()` checks both.

- [ ] **Step 7: Register the singleton**

In `src/AuthServiceProvider.php`, after the `UserModelModifier` binding:

```php
        $this->app->singleton(DatabaseSeederModifier::class);
```

Add `use JamesGifford\Auth\Installer\DatabaseSeederModifier;` to the imports.

- [ ] **Step 8: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/Installer/DatabaseSeederModifierTest.php
```

Expected: PASS. If `test_analyze_fails_closed_on_a_short_name_collision` fails, check that the collision path sets `shortNamesAvailable: false` while leaving `unusualReason` null — `isModifiable()` must return false from the flag alone.

- [ ] **Step 9: Hand off (do not run git)**

Files ready: `tests/Support/Fixtures/DatabaseSeeders/` (4 files), `src/Installer/DatabaseSeederAnalysis.php`, `src/Installer/SeederCallResolver.php`, `src/Installer/PackageSeederRemover.php`, `src/Installer/DatabaseSeederModifier.php`, `src/AuthServiceProvider.php`, `tests/Feature/Installer/DatabaseSeederModifierTest.php`.

Suggested message: `Add DatabaseSeederModifier with AST wiring and surgical reversion`

---

## Task 3: Install integration

**Files:**
- Modify: `src/Console/Commands/AuthInstallCommand.php` — `$signature` (line 37-49), constructor (line 52-60), `buildPlan()` (line 205), `displayPlan()` (line 289), `skipReason()` (line 321), `executeInstall()` (line 514), plus new methods
- Test: `tests/Feature/Installer/AuthInstallCommandTest.php`

**Interfaces:**
- Consumes: `DatabaseSeederModifier` (Task 2).
- Produces: `--skip-database-seeder` flag; plan key `wire_database_seeder`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Installer/AuthInstallCommandTest.php`:

```php
    // ---- DatabaseSeeder wiring ----

    public function test_install_wires_both_seeders_with_imports(): void
    {
        $file = $this->stubDatabaseSeeder();

        Artisan::call('jamesgifford:auth:install', ['--force' => true]);

        $code = (string) file_get_contents($file);
        $this->assertStringContainsString('use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;', $code);
        $this->assertStringContainsString('$this->call(AccountRoleSeeder::class);', $code);
        $this->assertStringContainsString('$this->call(DevDataSeeder::class);', $code);
        $this->assertStringContainsString('// User::factory(10)->create();', $code);
        $this->assertFileDoesNotExist($file.'.bak');
    }

    public function test_setup_wires_both_seeders_without_the_dev_data_flag(): void
    {
        // account_roles is production data. A plain `setup` — no --with-dev-data
        // — must still wire the role seeder, and the dev call goes in too (it
        // self-guards; the flag governs THIS run's seeding, not the wiring).
        $file = $this->stubDatabaseSeeder();

        Artisan::call('jamesgifford:auth:setup', ['--force' => true]);

        $code = (string) file_get_contents($file);
        $this->assertStringContainsString('$this->call(AccountRoleSeeder::class);', $code);
        $this->assertStringContainsString('$this->call(DevDataSeeder::class);', $code);

        // And the role call must NOT be inside the environment check.
        $rolePos = strpos($code, '$this->call(AccountRoleSeeder::class);');
        $ifPos = strpos($code, 'app()->environment(');
        $this->assertIsInt($rolePos);
        $this->assertIsInt($ifPos);
        $this->assertLessThan($ifPos, $rolePos, 'roles must be seeded unconditionally');
    }

    public function test_install_leaves_an_already_wired_seeder_alone(): void
    {
        // Wired, but not in our emitted form — detection is semantic.
        $file = $this->stubDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;
        use JamesGifford\Auth\Database\DevDataSeeder;
        use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(AccountRoleSeeder::class);

                if (app()->environment('local', 'staging')) {
                    $this->call(DevDataSeeder::class);
                }
            }
        }
        PHP);
        $before = (string) file_get_contents($file);

        Artisan::call('jamesgifford:auth:install', ['--force' => true]);

        $this->assertSame($before, (string) file_get_contents($file), 'an already-wired file must not be touched');
    }

    public function test_install_does_not_wire_when_skipped_by_flag(): void
    {
        $file = $this->stubDatabaseSeeder();

        Artisan::call('jamesgifford:auth:install', ['--force' => true, '--skip-database-seeder' => true]);

        $this->assertStringNotContainsString('AccountRoleSeeder', (string) file_get_contents($file));
    }

    public function test_install_wiring_is_idempotent_across_reruns(): void
    {
        $file = $this->stubDatabaseSeeder();

        Artisan::call('jamesgifford:auth:install', ['--force' => true]);
        Artisan::call('jamesgifford:auth:install', ['--force' => true]);

        $this->assertSame(
            1,
            substr_count((string) file_get_contents($file), '$this->call(AccountRoleSeeder::class);'),
        );
    }

    public function test_install_prints_instructions_and_succeeds_when_run_is_missing(): void
    {
        $file = $this->stubDatabaseSeeder(<<<'PHP'
        <?php

        namespace Database\Seeders;

        class DatabaseSeeder
        {
            public function somethingElse(): void
            {
                //
            }
        }
        PHP);

        $exit = Artisan::call('jamesgifford:auth:install', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'a convenience wiring must never fail the install');
        $this->assertStringContainsString('AccountRoleSeeder::class', $output);
        $this->assertStringNotContainsString('AccountRoleSeeder', (string) file_get_contents($file));
    }

    /**
     * Write a throwaway DatabaseSeeder into the testbench app and register it
     * for cleanup. Never point the installer at a shared fixture — it rewrites
     * the file in place.
     */
    private function stubDatabaseSeeder(?string $code = null): string
    {
        $dir = database_path('seeders');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.DIRECTORY_SEPARATOR.'DatabaseSeeder.php';
        file_put_contents($file, $code ?? <<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                // User::factory(10)->create();
            }
        }
        PHP);

        $this->beforeApplicationDestroyed(static function () use ($file): void {
            @unlink($file);
            @unlink($file.'.bak');
        });

        return $file;
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter 'test_install_(wires|leaves_an_already|does_not_wire|wiring|prints_instructions)' tests/Feature/Installer/AuthInstallCommandTest.php
```

Expected: FAIL — the file is untouched and `--skip-database-seeder` is unrecognized. (`test_install_leaves_an_already_wired_seeder_alone` passes already; it is a regression guard.)

- [ ] **Step 3: Add the flag and the dependency**

Add to `$signature` after the `--skip-user-model` line:

```
        {--skip-database-seeder : Skip wiring the package seeders into database/seeders/DatabaseSeeder.php}
```

Add to the constructor's promoted properties:

```php
        private readonly DatabaseSeederModifier $seederModifier,
```

and `use JamesGifford\Auth\Installer\DatabaseSeederModifier;` to the imports.

- [ ] **Step 4: Extend the plan**

In `buildPlan()`:

```php
            'wire_database_seeder' => $this->needsDatabaseSeederWiring() && ! $this->option('skip-database-seeder'),
```

In `displayPlan()`'s `$rows`:

```php
            'wire_database_seeder' => 'Wire package seeders into database/seeders/DatabaseSeeder.php',
```

In `skipReason()`'s `match`:

```php
            'wire_database_seeder' => $this->option('skip-database-seeder') ? 'skipped via flag' : 'already wired',
```

Add the helpers:

```php
    /**
     * True whenever a call is missing — deliberately independent of whether the
     * file can be safely edited. An unusual file still SHOWS the planned step
     * and degrades to instructions at execution time; hiding the row would drop
     * it with no explanation. Mirrors {@see needsUserModelModification()}.
     */
    private function needsDatabaseSeederWiring(): bool
    {
        return $this->seederModifier->analyze($this->databaseSeederPath())->needsWiring();
    }

    private function databaseSeederPath(): string
    {
        return database_path('seeders'.DIRECTORY_SEPARATOR.'DatabaseSeeder.php');
    }
```

- [ ] **Step 5: Implement the execution step**

In `executeInstall()`, after the `modify_user_model` block:

```php
        if ($plan['wire_database_seeder']) {
            $this->executeWireDatabaseSeeder();
        }
```

The missing `if (! ...) return false` is deliberate; the method returns `void` so it is structural rather than a convention someone can break by accident.

```php
    /**
     * Wire the package seeders into the consumer's DatabaseSeeder.
     *
     * Returns void, not bool, because this step can NEVER fail the install.
     * Unlike the User model modification — whose traits the package cannot
     * function without — this wiring is a convenience for future
     * `migrate:fresh --seed` runs. On any problem applyTransient has already
     * restored the file, and the install's real work (schema, roles, the
     * public_id lock) is done and correct.
     */
    private function executeWireDatabaseSeeder(): void
    {
        $this->newLine();
        $this->info('→ Wiring seeders into DatabaseSeeder...');

        $file = $this->databaseSeederPath();
        $analysis = $this->seederModifier->analyze($file);

        if (! $analysis->isModifiable()) {
            $reason = $analysis->unusualReason
                ?? 'the file already imports a different class under one of the seeder names';
            $this->warn("Automatic wiring is not safe here: {$reason}.");
            $this->displayManualSeederInstructions($file);

            return;
        }

        $updated = $this->seederModifier->modify($file, $analysis);

        if ($updated === null) {
            $this->info('DatabaseSeeder is already wired. Nothing to do.');

            return;
        }

        $original = (string) file_get_contents($file);

        $this->newLine();
        $this->line('Proposed changes:');
        $this->newLine();
        foreach (array_diff(explode("\n", $updated), explode("\n", $original)) as $line) {
            $this->line('<info>+</info> '.$line);
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Apply these changes?', true)) {
            $this->info('DatabaseSeeder wiring skipped.');
            $this->displayManualSeederInstructions($file);

            return;
        }

        try {
            $this->seederModifier->applyTransient(
                $file,
                $updated,
                verify: function () use ($file): void {
                    if ($this->seederModifier->analyze($file)->needsWiring()) {
                        throw new RuntimeException('the expected seeder calls were not present after wiring');
                    }
                },
            );
        } catch (Throwable $e) {
            $this->warn('Could not wire DatabaseSeeder: '.$e->getMessage());
            $this->line('It was left unchanged (no backup file remains). Wire it by hand:');
            $this->displayManualSeederInstructions($file);

            return;
        }

        $this->info('✓ DatabaseSeeder wired ('.$this->relativeToBase($file).'). No backup file is left behind.');
    }

    private function displayManualSeederInstructions(string $file): void
    {
        $this->newLine();
        $this->line('Add the following to '.$this->relativeToBase($file).':');
        $this->newLine();
        $this->line('  Add to the use statements at the top of the file:');
        $this->newLine();
        $this->line('      use JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder;');
        $this->line('      use JamesGifford\\Auth\\Database\\DevDataSeeder;');
        $this->newLine();
        $this->line('  Add to the top of run():');
        $this->newLine();
        $this->line('      // Required account roles — every environment.');
        $this->line('      $this->call(AccountRoleSeeder::class);');
        $this->newLine();
        $this->line('      // Development fixtures — permitted environments only.');
        $this->line("      if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {");
        $this->line('          $this->call(DevDataSeeder::class);');
        $this->line('      }');
    }
```

`relativeToBase()` already exists (line 1104); `RuntimeException` and `Throwable` are already imported (lines 26-27).

- [ ] **Step 6: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/Installer/AuthInstallCommandTest.php
```

Expected: PASS, including pre-existing tests. Several assert on the plan display — if one counts rows or matches exact output, update it for the new row.

- [ ] **Step 7: Hand off (do not run git)**

Files ready: `src/Console/Commands/AuthInstallCommand.php`, `tests/Feature/Installer/AuthInstallCommandTest.php`.

Suggested message: `Wire DatabaseSeeder automatically during install`

---

## Task 4: Uninstall integration

**Files:**
- Modify: `src/Console/Commands/AuthUninstallCommand.php` — constructor (line 48), `handle()` (after line 82), one new method
- Test: `tests/Feature/Installer/AuthUninstallCommandTest.php`

**Interfaces:**
- Consumes: `DatabaseSeederModifier` (Task 2).
- Produces: nothing.

**Why this matters:** once the package is `composer remove`d, a leftover `$this->call(DevDataSeeder::class)` references a class that no longer autoloads, and the next `db:seed` fatals.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Installer/AuthUninstallCommandTest.php`:

```php
    // ---- DatabaseSeeder reversion ----

    public function test_uninstall_removes_only_the_package_seeders(): void
    {
        $file = $this->stubWiredDatabaseSeeder();

        Artisan::call('jamesgifford:auth:uninstall', ['--force' => true]);

        $code = (string) file_get_contents($file);
        $this->assertStringNotContainsString('AccountRoleSeeder', $code);
        $this->assertStringNotContainsString('DevDataSeeder', $code);
        $this->assertStringContainsString('$this->call(ProductSeeder::class);', $code);
        $this->assertFileDoesNotExist($file.'.bak');
    }

    public function test_uninstall_leaves_an_unwired_seeder_untouched(): void
    {
        $file = $this->stubDatabaseSeederFile(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(ProductSeeder::class);
            }
        }
        PHP);
        $before = (string) file_get_contents($file);

        Artisan::call('jamesgifford:auth:uninstall', ['--force' => true]);

        $this->assertSame($before, (string) file_get_contents($file));
    }

    private function stubWiredDatabaseSeeder(): string
    {
        return $this->stubDatabaseSeederFile(<<<'PHP'
        <?php

        namespace Database\Seeders;

        use Illuminate\Database\Seeder;
        use JamesGifford\Auth\Database\DevDataSeeder;
        use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;

        class DatabaseSeeder extends Seeder
        {
            public function run(): void
            {
                $this->call(AccountRoleSeeder::class);

                if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {
                    $this->call(DevDataSeeder::class);
                }

                $this->call(ProductSeeder::class);
            }
        }
        PHP);
    }

    private function stubDatabaseSeederFile(string $code): string
    {
        $dir = database_path('seeders');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.DIRECTORY_SEPARATOR.'DatabaseSeeder.php';
        file_put_contents($file, $code);

        $this->beforeApplicationDestroyed(static function () use ($file): void {
            @unlink($file);
            @unlink($file.'.bak');
        });

        return $file;
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter 'test_uninstall_(removes_only|leaves_an_unwired)' tests/Feature/Installer/AuthUninstallCommandTest.php
```

Expected: the first FAILS — the wiring survives. The second passes already; it is a regression guard.

- [ ] **Step 3: Inject the modifier**

Add to the constructor's promoted properties:

```php
        private readonly DatabaseSeederModifier $seederModifier,
```

and `use JamesGifford\Auth\Installer\DatabaseSeederModifier;` to the imports.

- [ ] **Step 4: Implement the reversion**

In `handle()`, immediately after `$this->revertUserModel();` (line 82):

```php
        $this->revertDatabaseSeeder();
```

Add the method next to `revertUserModel()`:

```php
    /**
     * Remove the package's seeder calls from the consumer's DatabaseSeeder,
     * and nothing else — their own seeders, and any condition they put other
     * work into, are preserved.
     *
     * Not merely tidiness: once the package is composer-removed, a leftover
     * $this->call(DevDataSeeder::class) references a class that no longer
     * autoloads, and the next db:seed fatals.
     */
    private function revertDatabaseSeeder(): void
    {
        $this->newLine();

        $file = database_path('seeders'.DIRECTORY_SEPARATOR.'DatabaseSeeder.php');
        $analysis = $this->seederModifier->analyze($file);

        if (! $analysis->hasAccountRoleSeederCall && ! $analysis->hasDevDataSeederCall) {
            $this->line('Your DatabaseSeeder does not call the package\'s seeders, so no changes');
            $this->line('are needed there.');

            return;
        }

        $updated = $this->seederModifier->reverseModify($file, $analysis);

        if ($updated === null) {
            $this->line('The package can\'t safely auto-edit your DatabaseSeeder');
            $this->line('('.($analysis->unusualReason ?? 'unusual structure').'). Remove these by hand:');
            $this->printManualSeederRemoval();

            return;
        }

        try {
            $this->seederModifier->applyTransient(
                $file,
                $updated,
                verify: function () use ($file): void {
                    $check = $this->seederModifier->analyze($file);
                    if ($check->hasAccountRoleSeederCall || $check->hasDevDataSeederCall) {
                        throw new RuntimeException('package seeder calls were still present after reversion');
                    }
                },
            );
        } catch (Throwable $e) {
            $this->warn('Could not auto-revert your DatabaseSeeder: '.$e->getMessage());
            $this->line('It was left unchanged. Remove the package additions by hand:');
            $this->printManualSeederRemoval();

            return;
        }

        $this->info('✓ Reverted your DatabaseSeeder ('.$this->displayPath($file).'):');
        if ($analysis->hasAccountRoleSeederCall) {
            $this->line('  • removed the AccountRoleSeeder call and its import');
        }
        if ($analysis->hasDevDataSeederCall) {
            $this->line('  • removed the DevDataSeeder call and its import');
        }
        $this->line('  All other seeders were preserved. No backup file was left behind.');
    }

    private function printManualSeederRemoval(): void
    {
        $this->newLine();
        $this->line('      use JamesGifford\\Auth\\Database\\Seeders\\AccountRoleSeeder;');
        $this->line('      use JamesGifford\\Auth\\Database\\DevDataSeeder;');
        $this->newLine();
        $this->line('      $this->call(AccountRoleSeeder::class);');
        $this->line('      $this->call(DevDataSeeder::class);   (and any condition wrapping only it)');
    }
```

Confirm `RuntimeException` and `Throwable` are imported in this file; add them if not.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/Installer/AuthUninstallCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Hand off (do not run git)**

Files ready: `src/Console/Commands/AuthUninstallCommand.php`, `tests/Feature/Installer/AuthUninstallCommandTest.php`.

Suggested message: `Revert DatabaseSeeder wiring on uninstall`

---

## Task 5: Documentation, setup repair, and the verification gate

**Files:**
- Modify: `src/Console/Commands/AuthSetupCommand.php:195-212` (`displayNextSteps`)
- Modify: `tests/Feature/Console/AuthSetupCommandTest.php:102`
- Modify: `README.md:357`, `config/auth-dev.php:25`, `resources/boost/skills/jamesgifford-auth/SKILL.md`, `CHANGELOG.md`

**Interfaces:** none.

- [ ] **Step 1: Repair the existing setup test**

`test_completion_output_lists_next_steps` asserts that `database/seeders/DatabaseSeeder.php`, `AccountRoleSeeder::class`, and `DevDataSeeder::class` appear *after* "Setup complete." Those become false once the bullet is removed. Replace that group with:

```php
        // The next-steps block must FOLLOW completion (not scroll away
        // mid-run like install's own Step 2 output does).
        $afterComplete = (string) strstr($output, 'Setup complete.');
        $this->assertStringContainsString('boost:update', $afterComplete);
        $this->assertStringContainsString('boost:install', $afterComplete);

        // Seeder wiring moved into install: performed and reported at that point
        // in the run, no longer deferred to a manual next step.
        $this->assertStringNotContainsString('database/seeders/DatabaseSeeder.php', $afterComplete);
```

The negative assertion is what makes this a real red — it fails while the bullet is still present, and passes once Step 3 removes it.

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit --filter test_completion_output_lists_next_steps tests/Feature/Console/AuthSetupCommandTest.php
```

Expected: FAIL on `assertStringNotContainsString`.

- [ ] **Step 3: Remove the redundant bullet**

```php
    /**
     * Post-setup pointers. Install prints its own next steps mid-run at Step 2,
     * where they scroll away. The DatabaseSeeder wiring used to be listed here
     * as a manual step; install now performs it and reports it inline, so only
     * the Boost reminder — genuinely still the operator's to action — remains.
     */
    private function displayNextSteps(): void
    {
        $this->newLine();
        $this->line('Next steps:');
        $this->newLine();
        $this->line('  • Using Laravel Boost? Run `php artisan boost:update` to install this');
        $this->line("    package's AI skill (first-time Boost setup uses `boost:install`).");
        $this->line('    Not using Boost? No action needed.');
    }
```

- [ ] **Step 4: Update `config/auth-dev.php`**

Replace the recommended-wiring comment block (lines 25-36) with:

```
| Install wires this into database/seeders/DatabaseSeeder.php for you, and
| uninstall removes it again. Opt out with
| `jamesgifford:auth:install --skip-database-seeder`. The wiring it writes:
|
|     public function run(): void
|     {
|         // Required account roles — every environment.
|         $this->call(AccountRoleSeeder::class);
|
|         // Development fixtures — permitted environments only.
|         if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {
|             $this->call(DevDataSeeder::class);
|         }
|     }
|
| The environment check reads the `environments` key below rather than
| hardcoding a list, so adding an environment there is all it takes. The
| ['local', 'staging'] fallback matters when the app's config is cached and this
| file was never published — mergeConfigFrom is skipped in that case. The seeder
| self-guards regardless, and always refuses production.
```

- [ ] **Step 5: Update `README.md`**

In "Seeding from your DatabaseSeeder", note that install writes this automatically (opt out with `--skip-database-seeder`) and uninstall removes it, then replace the hardcoded env check with the config-reading form:

```php
use JamesGifford\Auth\Database\Seeders\AccountRoleSeeder;
use JamesGifford\Auth\Database\DevDataSeeder;

public function run(): void
{
    // Auth: required account roles — every environment.
    $this->call(AccountRoleSeeder::class);

    // Auth: development fixtures — permitted environments only. Reads the
    // `environments` key, so adding one there is all it takes; the seeder
    // self-guards too, and always refuses production.
    if (app()->environment(config('jamesgifford.auth-dev.environments', ['local', 'staging']))) {
        $this->call(DevDataSeeder::class);
    }

    // App-specific seeders:
    // $this->call(YourAppSeeder::class);
}
```

Add a note that a `DatabaseSeeder` already calling these seeders is detected and left alone, however the calls were written.

- [ ] **Step 6: Update the Boost skill**

In `resources/boost/skills/jamesgifford-auth/SKILL.md`, near the existing `DevDataSeeder` mention (around line 166): install wires both seeders into `DatabaseSeeder.php`, detects a file that already calls them and leaves it alone, `--skip-database-seeder` opts out, uninstall removes only the package's calls. Keep identifiers spelled exactly as in code.

- [ ] **Step 7: Update `CHANGELOG.md`**

Insert above the `## [1.2.2]` heading:

```markdown
## [1.2.3] - 2026-08-10

### Added
- `jamesgifford:auth:install` now wires `AccountRoleSeeder` and `DevDataSeeder` into `database/seeders/DatabaseSeeder.php`, so `php artisan db:seed` and `migrate:fresh --seed` seed roles in every environment and the dev cast in permitted ones. The edit is a format-preserving AST modification with a diff preview and a `--skip-database-seeder` opt-out. An already-wired file is detected through the file's import map — by what the calls register, not by matching the emitted text — and left untouched, so adjusting the wiring by hand does not cause a duplicate on the next install. A file that cannot be edited safely is left alone with instructions printed; the wiring never fails the install.
- `jamesgifford:auth:uninstall` removes the package's seeder calls and imports, and nothing else: the developer's own seeders are preserved, as is any condition they added other statements to.

### Changed
- The dev-fixture environment check reads `config('jamesgifford.auth-dev.environments')` instead of hardcoding `local`/`staging`, so an environment added to that config is honored. README and `config/auth-dev.php` updated to match.
- `jamesgifford:auth:setup`'s closing next-steps block no longer lists the DatabaseSeeder wiring as a manual step; install performs and reports it.

### Internal
- Extracted `Installer\PhpFileWriter` from `UserModelModifier` so both file modifiers share one backup-write-validate-restore transaction.
```

- [ ] **Step 8: Format and analyse**

```bash
composer format
composer analyse
```

Expected: no PHPStan errors. The anonymous removal visitor and the `Node\ArrayItem` filtering are the likely sources; fix by tightening annotations, not by widening to `mixed`.

- [ ] **Step 9: Full suite, both drivers, sequentially**

```bash
composer test
composer test:sqlite
```

Expected: PASS on both, with the 2 known skips per driver. Run them one after the other, never concurrently — they share a database name and a `TestCase` purge/disconnect step. Any additional skip is a regression to investigate.

- [ ] **Step 10: Report**

State the actual result of each command with its output. If anything failed, say so plainly and fix it before claiming completion.

- [ ] **Step 11: Hand off (do not run git)**

Files ready: `src/Console/Commands/AuthSetupCommand.php`, `tests/Feature/Console/AuthSetupCommandTest.php`, `README.md`, `config/auth-dev.php`, `resources/boost/skills/jamesgifford-auth/SKILL.md`, `CHANGELOG.md`, plus anything `composer format` touched.

Suggested message: `Release 1.2.3: wire DatabaseSeeder during install`
