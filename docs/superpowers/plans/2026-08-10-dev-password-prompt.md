# Dev Password Rename and Prompt Implementation Plan — 1.2.2

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the dev-user password env var to `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` (and its config key to `users_password`) so both say what the password is *for*, then name it in the setup command's pre-lock pause as a copy/paste `.env` line.

**Architecture:** A rename across config, one seeder read, docs, and tests; plus an output-only addition to one method. No new classes, no new state.

**Tech Stack:** PHP 8.4, Laravel 13, PHPUnit 11, Orchestra Testbench 11.

**Release:** tagged `1.2.2`.

## Global Constraints

- **Never run `git` commands.** James stages and commits everything himself. Each task ends with a hand-off step listing the files and a suggested message.
- **No backwards compatibility.** No aliasing of the old variable name, no deprecation shim, no fallback read. The old name simply stops working.
- **`CHANGELOG.md:124` must NOT be edited.** It records `JAMESGIFFORD_AUTH_DEV_PASSWORD` under the entry that shipped it, and a changelog is a historical record of what was true at that release. The rename is announced in the new `1.2.2` entry instead.
- **No new classes, constants, or config keys** beyond the rename itself. If this plan starts growing any, the scope has drifted.
- **`expectsOutputToContain` matches one distinct output line per expectation** (Mockery assigns one write per expectation). Assert one substring per line, as the existing pause tests do at `tests/Feature/Console/AuthSetupCommandTest.php:312`.
- **Two drivers, sequentially.** `composer test` (MariaDB, the `phpunit.xml` default) then `composer test:sqlite`. Never concurrently — they share a database name and a `TestCase` purge/disconnect step.

## The rename

| | Before | After |
| --- | --- | --- |
| Env var | `JAMESGIFFORD_AUTH_DEV_PASSWORD` | `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` |
| Config key | `jamesgifford.auth-dev.password` | `jamesgifford.auth-dev.users_password` |

Both carry the same motivation: the key sits as a sibling of `environments`, `accounts`, and `users` in `config/auth-dev.php`, where a bare `password` does not say whose.

The default value stays `'password'`.

## Why the prompt goes in the pause, not the closing next-steps block

The password is hashed at seed time, which happens in Step 3. The pause runs at Step 2. A reminder printed in `displayNextSteps()` would arrive after the cast was already created with the fallback, so acting on it would require a re-seed. The pause's existing closing line — "press Ctrl-C, edit config or .env, then re-run" — already describes the right recovery.

**Known limitation, accepted:** the pause is skipped under `--force` and on a non-interactive terminal, so an unattended `setup --with-dev-data --force` never sees the variable name. An unattended run cannot act on the reminder anyway.

## Deliberately NOT doing

Introducing a `PASSWORD_ENV_KEY` constant on `DevDataSeeder`. The literal appears in the config file, two test files, the README, and the Boost skill; a constant would not collapse those, and adding one turns this into a code-plus-tests change for no gain. The string is printed as a literal.

---

## Task 1: Rename the env var and config key

**Files:**
- Modify: `config/auth-dev.php:53-58`
- Modify: `src/Database/DevDataSeeder.php:135`
- Modify: `tests/Feature/Config/PackageEnvVariablesTest.php:21-36`
- Modify: `tests/Feature/Console/DevDataDefaultFixturesTest.php:54-64`, `:130`
- Modify: `README.md:327`, `:335`
- Modify: `resources/boost/skills/jamesgifford-auth/SKILL.md:180`

**Interfaces:**
- Consumes: nothing.
- Produces: config key `jamesgifford.auth-dev.users_password`, read by `DevDataSeeder::seed()`. Task 2 prints the env var name that feeds it.

- [ ] **Step 1: Update the tests to the new names**

Tests first, so they pin the rename and fail until the code follows.

In `tests/Feature/Config/PackageEnvVariablesTest.php`, replace the body of `test_dev_data_config_reads_the_prefixed_password_env_var()`:

```php
    public function test_dev_data_config_reads_the_prefixed_password_env_var(): void
    {
        $key = 'JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD';

        $_SERVER[$key] = 'secret-from-env';
        try {
            $config = require $this->packageRoot().'/config/auth-dev.php';
            $this->assertSame('secret-from-env', $config['users_password']);
        } finally {
            unset($_SERVER[$key]);
        }

        // Falls back to 'password' when the env var is absent.
        $config = require $this->packageRoot().'/config/auth-dev.php';
        $this->assertSame('password', $config['users_password']);
    }
```

In `tests/Feature/Console/DevDataDefaultFixturesTest.php`, replace both assertions in `test_published_config_keeps_the_password_env_sourced_with_no_literal()` (lines 62-63):

```php
        // Password comes from the environment, never a stored literal.
        $this->assertStringContainsString("env('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD'", $contents);
        $this->assertStringNotContainsString("'users_password' => '", $contents);
```

**The second assertion must be renamed along with the key, not left alone.** Its current needle is `"'password' => '"`, which requires a quote immediately before `password`. In `'users_password' => '` the preceding character is `_`, so the needle would stop matching and the assertion would pass vacuously — guarding nothing while looking like it still guards against a committed credential.

Update the comment at line 130:

```php
        // Default fallback for JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD is 'password'; hashed at seed time.
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Feature/Config/PackageEnvVariablesTest.php tests/Feature/Console/DevDataDefaultFixturesTest.php
```

Expected: FAIL. `PackageEnvVariablesTest` fails on an undefined `users_password` key; `DevDataDefaultFixturesTest` fails because the published config still contains the old `env()` name.

- [ ] **Step 3: Rename in the config file**

In `config/auth-dev.php`, replace lines 53-58:

```php
    // Shared password for EVERY seeded dev user. Sourced from the environment
    // (JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD in your .env) so no credential is
    // committed here; it is hashed at seed time and never stored in plaintext.
    //
    // The password is NOT set per-user in this file — change it in .env.
    'users_password' => env('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD', 'password'),
```

- [ ] **Step 4: Rename the seeder's read**

In `src/Database/DevDataSeeder.php`, line 135:

```php
        $password = Hash::make((string) ($config['users_password'] ?? 'password'));
```

The surrounding comment describes the hashing guard, not the key, and needs no change. Check the class docblock (lines 20-46) for any mention of the old name and update it if present.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/Config/ tests/Feature/Console/
```

Expected: PASS. `DevDataSeeder`'s own tests exercise seeding end to end, so a missed rename in the read path surfaces here as a wrong password hash, not just a config assertion.

- [ ] **Step 6: Update the documentation**

`README.md:327` — the env var table row:

```markdown
| `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` | Shared password for seeded dev users (`config/jamesgifford/auth-dev.php`); hashed at seed time. |
```

`README.md:335` — in the dev-data seeding paragraph, change "sourced from `JAMESGIFFORD_AUTH_DEV_PASSWORD`" to "sourced from `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD`".

`resources/boost/skills/jamesgifford-auth/SKILL.md:180`:

```markdown
- `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` — shared password for seeded dev users
```

- [ ] **Step 7: Confirm nothing was missed**

```bash
grep -rn "JAMESGIFFORD_AUTH_DEV_PASSWORD" --include="*.php" --include="*.md" . | grep -v vendor
```

Expected: exactly one hit — `CHANGELOG.md:124`, the historical 1.1.x entry, which stays. Any other hit is a missed rename.

```bash
grep -rn "auth-dev.password\|\['password'\]" --include="*.php" src/ config/ | grep -v vendor
```

Expected: no hits. (`setAttribute('password', ...)` in the seeder is the User model's own column and is unrelated.)

- [ ] **Step 8: Hand off (do not run git)**

Files ready: `config/auth-dev.php`, `src/Database/DevDataSeeder.php`, `tests/Feature/Config/PackageEnvVariablesTest.php`, `tests/Feature/Console/DevDataDefaultFixturesTest.php`, `README.md`, `resources/boost/skills/jamesgifford-auth/SKILL.md`.

Suggested message: `Rename the dev-user password env var and config key`

---

## Task 2: Show the password line in the setup pause

**Files:**
- Modify: `src/Console/Commands/AuthSetupCommand.php:139` (call site), `:375-381` (docblock and signature), `:402-406` (the `.env` block)
- Test: `tests/Feature/Console/AuthSetupCommandTest.php`

**Interfaces:**
- Consumes: the renamed env var from Task 1.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Console/AuthSetupCommandTest.php`, next to the existing pause tests:

```php
    public function test_pause_names_the_dev_password_env_var_under_with_dev_data(): void
    {
        $this->app['env'] = 'local'; // dev-data allowlisted

        // One substring per output line — see the note on expectsOutputToContain
        // in test_interactive_run_pauses_with_educational_guidance_before_the_lock.
        $this->artisan('jamesgifford:auth:setup', ['--with-dev-data' => true])
            ->expectsOutputToContain('Dev users all share one password, read from the same file. Add')
            ->expectsOutputToContain('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD=password')
            ->expectsQuestion('Press ENTER to continue (locking public_id and finishing setup)', '')
            ->assertExitCode(0);
    }

    public function test_pause_omits_the_dev_password_env_var_without_dev_data(): void
    {
        // No cast will be seeded, so the reminder would be noise.
        $this->artisan('jamesgifford:auth:setup')
            ->doesntExpectOutputToContain('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD')
            ->expectsQuestion('Press ENTER to continue (locking public_id and finishing setup)', '')
            ->assertExitCode(0);
    }
```

If `doesntExpectOutputToContain()` is unavailable on this Laravel version, replace the second test with a `--force` run and a plain string assertion — the flag skips the pause, so the variable must be absent either way:

```php
    public function test_pause_omits_the_dev_password_env_var_without_dev_data(): void
    {
        Artisan::call('jamesgifford:auth:setup', ['--force' => true]);

        $this->assertStringNotContainsString('JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD', Artisan::output());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit --filter 'test_pause_(names|omits)_the_dev_password_env_var' tests/Feature/Console/AuthSetupCommandTest.php
```

Expected: the first test FAILS — the output never contains `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD=password`. The second passes already (nothing prints it yet); that is fine, it is a regression guard.

- [ ] **Step 3: Thread the flag into the pause**

In `src/Console/Commands/AuthSetupCommand.php`, change the call site at line 139:

```php
            $this->educationalPause($withDevData);
```

and the signature at line 381:

```php
    private function educationalPause(bool $withDevData): void
```

`$withDevData` is already in scope at the call site (assigned at line 71).

- [ ] **Step 4: Add the reminder block**

In `educationalPause()`, replace this run of lines (currently 402-406):

```php
        $this->line('  • In your .env:');
        $this->newLine();
        $this->line("        {$usersEnv}=11");
        $this->line("        {$accountsEnv}=1001");
        $this->newLine();
```

with:

```php
        $this->line('  • In your .env:');
        $this->newLine();
        $this->line("        {$usersEnv}=11");
        $this->line("        {$accountsEnv}=1001");
        $this->newLine();

        // Only when a cast will actually be seeded. The password is hashed at
        // seed time in Step 3, so this pause is the last point at which setting
        // it still changes the outcome — after that it takes a re-seed.
        if ($withDevData) {
            $this->line('    Dev users all share one password, read from the same file. Add');
            $this->line('    this line before Step 3 seeds the cast — the value is hashed at');
            $this->line('    seed time, and "password" is what the seeder uses if you skip it:');
            $this->newLine();
            $this->line('        JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD=password');
            $this->newLine();
        }
```

- [ ] **Step 5: Update the method's docblock**

The existing docblock (lines 375-380) describes only the lock and the offsets:

```php
    /**
     * Interactive-only pause shown after the config is published and BEFORE the
     * public_id lock. It explains the irreversible lock and shows copy/paste
     * snippets for declaring ID offsets two ways — a config literal and an
     * environment variable — plus, when a dev cast will be seeded, the .env
     * line for the shared dev-user password. All of it belongs here rather than
     * in the closing next-steps block: the lock and the seeding both happen
     * after this point, so this is the last moment any of it is actionable
     * without a re-run.
     */
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Feature/Console/AuthSetupCommandTest.php
```

Expected: PASS, including the pre-existing pause tests — `test_interactive_run_pauses_with_educational_guidance_before_the_lock` and `test_prefix_reminder_is_shown_before_dev_data_is_seeded` both exercise the modified method.

- [ ] **Step 7: Hand off (do not run git)**

Files ready: `src/Console/Commands/AuthSetupCommand.php`, `tests/Feature/Console/AuthSetupCommandTest.php`.

Suggested message: `Name the dev-users password env var in the setup pause`

---

## Task 3: CHANGELOG and verification gate

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the entry**

Insert above the `## [1.2.1] - 2026-08-05` heading. Note this does **not** touch line 124's historical mention of the old name.

```markdown
## [1.2.2] - 2026-08-10

### Changed
- **Breaking:** the dev-user password env var is now `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` (was `JAMESGIFFORD_AUTH_DEV_PASSWORD`), and its config key is now `users_password` (was `password`) in `config/jamesgifford/auth-dev.php`. Both names now say what the password is for, rather than sitting ambiguously beside the `environments`, `accounts`, and `users` keys. There is no fallback to the old name — rename it in your `.env` and, if you published the dev config, in that file. The default value is unchanged (`password`).

### Added
- `jamesgifford:auth:setup --with-dev-data` now names `JAMESGIFFORD_AUTH_DEV_USERS_PASSWORD` in its pre-lock pause, as a copy/paste `.env` line alongside the existing ID-offset variables. The reminder appears at the pause rather than in the closing next-steps block because the password is hashed at seed time in Step 3 — printed at the end it would arrive after the cast was already created with the fallback. Runs without `--with-dev-data` are unchanged.
```

- [ ] **Step 2: Format and analyse**

```bash
composer format
composer analyse
```

Expected: no PHPStan errors.

- [ ] **Step 3: Full suite, both drivers, sequentially**

```bash
composer test
composer test:sqlite
```

Expected: PASS on both, with the 2 known skips per driver. Run them one after the other, never concurrently — they share a database name and a `TestCase` purge/disconnect step. Any additional skip is a regression to investigate.

- [ ] **Step 4: Report**

State the actual result of each command with its output. If anything failed, say so plainly and fix it before claiming completion.

- [ ] **Step 5: Hand off (do not run git)**

Files ready: `CHANGELOG.md`, plus anything `composer format` touched.

Suggested message: `Release 1.2.2: rename the dev-users password variable`
