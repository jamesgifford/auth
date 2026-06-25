<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Auth\PublicId\PrefixRegistry;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\TestCase;

class HasPublicIdTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
    }

    public function test_creating_model_auto_generates_public_id(): void
    {
        $model = FixtureModel::create(['name' => 'test']);

        $this->assertNotNull($model->public_id);
        $this->assertStringStartsWith('fix_', $model->public_id);
    }

    public function test_manually_set_public_id_is_preserved(): void
    {
        // Generate a valid one to use, since checksums must be correct
        $original = FixtureModel::create(['name' => 'first']);
        $reusedId = $original->public_id;
        $original->delete();

        $model = new FixtureModel;
        $model->name = 'second';
        $model->public_id = $reusedId;
        $model->save();

        $this->assertSame($reusedId, $model->fresh()->public_id);
    }

    public function test_get_route_key_name_returns_public_id(): void
    {
        $model = new FixtureModel;

        $this->assertSame('public_id', $model->getRouteKeyName());
    }

    public function test_where_public_id_scope_finds_model(): void
    {
        $model = FixtureModel::create(['name' => 'one']);

        $found = FixtureModel::wherePublicId($model->public_id)->first();

        $this->assertNotNull($found);
        $this->assertSame($model->id, $found->id);
    }

    public function test_where_public_id_in_scope_finds_multiple_models(): void
    {
        $a = FixtureModel::create(['name' => 'a']);
        $b = FixtureModel::create(['name' => 'b']);

        $found = FixtureModel::wherePublicIdIn([$a->public_id, $b->public_id])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $found);
        $this->assertSame($a->id, $found[0]->id);
        $this->assertSame($b->id, $found[1]->id);
    }

    public function test_trait_registers_model_with_prefix_registry_on_first_boot(): void
    {
        // Triggering an instance forces boot
        FixtureModel::create(['name' => 'boot-trigger']);

        $registry = $this->app->make(PrefixRegistry::class);

        $this->assertArrayHasKey(FixtureModel::class, $registry->all());
        $this->assertSame('fix', $registry->all()[FixtureModel::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../Support/migrations');
    }
}
