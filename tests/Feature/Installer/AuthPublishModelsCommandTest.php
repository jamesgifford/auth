<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\Installer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use JamesGifford\Auth\Accounts\Services\AccountService;
use JamesGifford\Auth\Installer\ModelPublisher;
use JamesGifford\Auth\Tests\Feature\Accounts\AccountsTestCase;
use JamesGifford\Auth\Tests\Support\Fixtures\User;

class AuthPublishModelsCommandTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPublishedModels();
    }

    protected function tearDown(): void
    {
        $this->cleanPublishedModels();
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

    public function test_prints_config_wiring_instructions(): void
    {
        Artisan::call('jamesgifford:auth:publish-models');
        $output = Artisan::output();

        $this->assertStringContainsString("'account' => \\App\\Models\\Account::class,", $output);
        $this->assertStringContainsString("'account_user' => \\App\\Models\\AccountUser::class,", $output);
        $this->assertStringContainsString("'account_role' => \\App\\Models\\AccountRole::class,", $output);
    }

    public function test_package_uses_the_app_account_model_after_config_is_wired(): void
    {
        $this->seedRoles();
        Artisan::call('jamesgifford:auth:publish-models');

        $accountFile = $this->app->path('Models/Account.php');
        $this->assertFileExists($accountFile);
        if (! class_exists('App\\Models\\Account', false)) {
            require $accountFile;
        }

        // Wire the model-resolution config as the command instructs.
        config(['jamesgifford.auth.models.account' => 'App\\Models\\Account']);

        $account = $this->app->make(AccountService::class)->create(User::factory()->create());

        $this->assertInstanceOf('App\\Models\\Account', $account);
    }

    public function test_published_account_user_functions_as_the_pivot(): void
    {
        $this->seedRoles();
        Artisan::call('jamesgifford:auth:publish-models');

        $accountUserFile = $this->app->path('Models/AccountUser.php');
        if (! class_exists('App\\Models\\AccountUser', false)) {
            require $accountUserFile;
        }

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
}
