<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Tests\Feature\PublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModel;
use JamesGifford\Auth\Tests\Support\Fixtures\FixtureModelWithUuid;
use JamesGifford\Auth\Tests\TestCase;

class HasPublicIdWithoutEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
    }

    public function test_public_id_is_generated_when_model_events_are_suppressed(): void
    {
        $model = Model::withoutEvents(fn () => FixtureModel::create(['name' => 'x']));

        $this->assertNotNull($model->public_id);
        $this->assertStringStartsWith('fix_', $model->public_id);
    }

    public function test_public_id_is_generated_by_save_quietly(): void
    {
        $model = new FixtureModel(['name' => 'x']);
        $model->saveQuietly();

        $this->assertNotNull($model->public_id);
        $this->assertStringStartsWith('fix_', $model->public_id);
    }

    public function test_public_id_is_generated_alongside_has_uuids(): void
    {
        $model = Model::withoutEvents(fn () => FixtureModelWithUuid::create(['name' => 'x']));

        $this->assertNotNull($model->id);
        $this->assertTrue(Str::isUuid((string) $model->id));
        $this->assertNotNull($model->public_id);
        $this->assertStringStartsWith('fxu_', $model->public_id);
    }

    public function test_explicitly_set_public_id_is_preserved_when_events_are_suppressed(): void
    {
        // Generate a valid one to use, since checksums must be correct.
        $original = FixtureModel::create(['name' => 'first']);
        $reusedId = $original->public_id;
        $original->delete();

        $model = new FixtureModel(['name' => 'second']);
        $model->public_id = $reusedId;
        $model->saveQuietly();

        $this->assertSame($reusedId, $model->fresh()->public_id);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../Support/migrations');
    }
}
