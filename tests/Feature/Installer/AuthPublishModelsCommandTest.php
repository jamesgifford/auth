<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Installer\ModelPublisher;
use JamesGifford\Auth\Models\Account as PackageAccount;
use JamesGifford\Auth\Models\AccountRole as PackageAccountRole;
use JamesGifford\Auth\Models\AccountUser as PackageAccountUser;
use JamesGifford\Auth\PackageModels;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AuthPublishModelsCommandTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPublishedModels();
        $this->cleanPublishedConfig();
    }

    protected function tearDown(): void
    {
        $this->cleanPublishedModels();
        $this->cleanPublishedConfig();
        parent::tearDown();
    }

    public function test_resolves_the_app_models_namespace_and_path(): void
    {
        $publisher = $this->app->make(ModelPublisher::class);

        $this->assertSame('App\\Models', $publisher->modelNamespace());
        $this->assertStringEndsWith('app'.DIRECTORY_SEPARATOR.'Models', $publisher->modelDirectory());
    }

    public function test_publishes_three_subclasses_with_content_derived_from_the_base_models(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $dir = $this->app->path('Models');

        $account = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'Account.php');
        $this->assertStringContainsString('namespace App\\Models;', $account);
        $this->assertStringContainsString('use JamesGifford\\Auth\\Models\\Account as BaseAccount;', $account);
        $this->assertStringContainsString("#[Fillable(['name', 'owner_id'])]", $account);
        $this->assertStringContainsString('class Account extends BaseAccount', $account);
        $this->assertStringContainsString("return 'account';", $account);

        $role = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'AccountRole.php');
        $this->assertStringContainsString("#[Fillable(['key', 'name', 'description', 'system', 'sort_order'])]", $role);
        $this->assertStringContainsString("'system' => 'boolean',", $role);
        // No public IDs on AccountRole, so no prefix method is written out.
        $this->assertStringNotContainsString('publicIdPrefix', $role);

        $accountUser = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'AccountUser.php');
        $this->assertStringContainsString("#[Fillable(['account_id', 'user_id', 'account_role_id', 'joined_at'])]", $accountUser);
        $this->assertStringContainsString("'joined_at' => 'datetime',", $accountUser);
        $this->assertStringContainsString('extends BaseAccountUser', $accountUser);
    }

    public function test_existing_models_are_skipped_not_overwritten(): void
    {
        $dir = $this->app->path('Models');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.DIRECTORY_SEPARATOR.'Account.php', "<?php\n// consumer custom marker zzz9\n");

        Artisan::call('jamesgifford:auth:publish-models');
        $output = Artisan::output();

        $this->assertStringContainsString('skipped', $output);
        $this->assertStringContainsString('consumer custom marker zzz9', (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'Account.php'));
        // The others were still created.
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'AccountUser.php');
        $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'AccountRole.php');
    }

    public function test_registers_all_published_subclasses_in_the_config_map(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $output = Artisan::output();

        $this->assertStringContainsString('Registered in', $output);
        $this->assertStringContainsString("'account' => \\App\\Models\\Account::class,", $output);
        $this->assertStringContainsString("'account_user' => \\App\\Models\\AccountUser::class,", $output);
        $this->assertStringContainsString("'account_role' => \\App\\Models\\AccountRole::class,", $output);

        $this->assertSame('App\\Models\\Account', config('jamesgifford.auth.models.account'));
        $this->assertSame('App\\Models\\AccountUser', config('jamesgifford.auth.models.account_user'));
        $this->assertSame('App\\Models\\AccountRole', config('jamesgifford.auth.models.account_role'));

        // Written as the consuming app's Pint would: imported where the short
        // name is free; Account's belongs to the base model the prefixes map
        // still imports, so it stays qualified (relative: the file has no
        // namespace). The base imports the map no longer uses are gone.
        $configFile = (string) file_get_contents($this->app->make(ModelPublisher::class)->publishedConfigPath());
        $this->assertStringContainsString("'account' => App\\Models\\Account::class,", $configFile);
        $this->assertStringContainsString("'account_user' => AccountUser::class,", $configFile);
        $this->assertStringContainsString("'account_role' => AccountRole::class,", $configFile);
        $this->assertStringContainsString("use App\\Models\\AccountRole;\nuse App\\Models\\AccountUser;\nuse App\\Models\\User;\nuse JamesGifford\\Auth\\Models\\Account;\n", $configFile);
        $this->assertStringNotContainsString('use JamesGifford\\Auth\\Models\\AccountRole;', $configFile);
        $this->assertStringNotContainsString('use JamesGifford\\Auth\\Models\\AccountUser;', $configFile);
    }

    public function test_publishing_the_config_file_first_is_not_required(): void
    {
        $this->assertFileDoesNotExist($this->app->make(ModelPublisher::class)->publishedConfigPath());

        Artisan::call('jamesgifford:auth:publish-models');

        $this->assertFileExists($this->app->make(ModelPublisher::class)->publishedConfigPath());
        $this->assertSame('App\\Models\\Account', config('jamesgifford.auth.models.account'));
    }

    public function test_rerunning_publish_models_is_idempotent(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $publisher = $this->app->make(ModelPublisher::class);
        $firstPass = (string) file_get_contents($publisher->publishedConfigPath());

        Artisan::call('jamesgifford:auth:publish-models');
        $secondPass = (string) file_get_contents($publisher->publishedConfigPath());

        $this->assertSame($firstPass, $secondPass);
        $this->assertSame(1, substr_count($secondPass, "'account' => App\\Models\\Account::class,"));
        $this->assertSame(1, substr_count($secondPass, 'use App\\Models\\AccountRole;'));
    }

    public function test_reports_no_mismatch_when_everything_is_registered(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $output = Artisan::output();

        $this->assertStringNotContainsString('Model-map mismatch detected', $output);
    }

    public function test_flags_a_published_but_unregistered_subclass(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');

        // Simulate the config drifting back to the base class after publishing.
        config(['jamesgifford.auth.models.account' => PackageAccount::class]);

        $publisher = $this->app->make(ModelPublisher::class);
        $consistency = $publisher->configConsistency();

        $accountRow = collect($consistency)->firstWhere('configKey', 'account');
        $this->assertSame('unregistered', $accountRow['status']);

        $accountUserRow = collect($consistency)->firstWhere('configKey', 'account_user');
        $this->assertSame('registered', $accountUserRow['status']);
    }

    public function test_default_behavior_resolves_base_classes_without_publishing(): void
    {
        $this->assertSame(PackageAccount::class, PackageModels::account());
        $this->assertSame(PackageAccountRole::class, PackageModels::accountRole());
        $this->assertSame(PackageAccountUser::class, PackageModels::accountUser());
    }

    public function test_flags_a_key_with_no_published_subclass(): void
    {
        $publisher = $this->app->make(ModelPublisher::class);
        $consistency = $publisher->configConsistency();

        foreach ($consistency as $row) {
            $this->assertSame('not_published', $row['status']);
        }
    }

    public function test_package_uses_the_app_account_model_after_publishing(): void
    {
        $this->seedRoles();
        Artisan::call('jamesgifford:auth:publish-models');
        $this->requirePublishedModels();

        // No manual config wiring — publish-models registered it automatically.
        $account = $this->app->make(AccountService::class)->create(User::factory()->create());

        $this->assertInstanceOf('App\\Models\\Account', $account);
    }

    public function test_published_account_user_functions_as_the_pivot(): void
    {
        $this->seedRoles();
        Artisan::call('jamesgifford:auth:publish-models');
        $this->requirePublishedModels();

        $user = User::factory()->create();
        $account = $this->app->make(AccountService::class)->create($user);

        // Hydrate the membership through a belongsToMany that uses the published
        // pivot subclass — exercising Eloquent's newPivot() path.
        $members = $account->belongsToMany(User::class, 'account_user', 'account_id', 'user_id')
            ->using('App\\Models\\AccountUser')
            ->withPivot(['account_role_id', 'joined_at'])
            ->withTimestamps()
            ->get();

        $this->assertCount(1, $members);
        $pivot = $members->first()->pivot;

        $this->assertInstanceOf('App\\Models\\AccountUser', $pivot);
        $this->assertTrue($pivot->isOwner(), 'Inherited role logic should work on the published pivot.');
        $this->assertInstanceOf(Carbon::class, $pivot->joined_at, 'Casts should apply on the published pivot.');
    }

    private function cleanPublishedModels(): void
    {
        if ($this->app === null) {
            return;
        }
        $dir = $this->app->path('Models');
        foreach (['Account', 'AccountUser', 'AccountRole'] as $name) {
            @unlink($dir.DIRECTORY_SEPARATOR.$name.'.php');
        }
    }

    private function cleanPublishedConfig(): void
    {
        if ($this->app === null) {
            return;
        }
        @unlink($this->app->make(ModelPublisher::class)->publishedConfigPath());
    }

    /**
     * App\ is not autoloadable in the package test suite, and class
     * definitions leak across the (randomized) run — require exactly once.
     */
    private function requirePublishedModels(): void
    {
        $dir = $this->app->path('Models');
        foreach (['Account', 'AccountRole', 'AccountUser'] as $name) {
            if (! class_exists("App\\Models\\{$name}", false)) {
                require $dir.DIRECTORY_SEPARATOR.$name.'.php';
            }
        }
    }
}
