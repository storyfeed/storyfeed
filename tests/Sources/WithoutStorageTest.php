<?php

namespace Storyfeed\Tests\Sources;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Exceptions\StorageDisabled;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Sources\Entry;
use Storyfeed\StoryfeedManager;
use Storyfeed\Tests\Fixtures\Models\Project;
use Storyfeed\Tests\Fixtures\Models\RegisteredProject;
use Storyfeed\Tests\Fixtures\Models\StoragelessProject;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\User;

/**
 * An app that composes and renders feeds from its own data, and never ran
 * core's migrations (#131).
 */
class WithoutStorageTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // Where an app's service provider would declare it: before the
        // package's booted callbacks decide what to schedule.
        $app->booting(fn () => $app->make(StoryfeedManager::class)->withoutStorage());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A connection with none of core's tables, and the app's own.
        config()->set('database.connections.bare', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('database.default', 'bare');

        Schema::create('storageless_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Relation::morphMap([
            'project' => Project::class,
            'storageless_project' => StoragelessProject::class,
            'registered_project' => RegisteredProject::class,
        ]);
    }

    #[Test]
    public function it_boots_with_no_tables(): void
    {
        foreach (config('storyfeed.tables') as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} exists");
        }

        $this->assertFalse(Storyfeed::usesStorage());
        $this->assertFalse(Storyfeed::isRecording());
    }

    #[Test]
    public function it_composes_and_renders_model_roles(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $user = (new User)->forceFill(['id' => 7, 'name' => 'Sally']);
        $project = new Project(['id' => 2, 'name' => 'Storyfeed', 'slug' => 'storyfeed']);

        $items = Storyfeed::compose()
            ->add(fn (Entry $entry) => $entry
                ->by('Tey Labs')
                ->headline(':actor shipped :object', ['actor' => $user, 'object' => $project]))
            ->get()
            ->toArray();

        $this->assertSame([], $queries);
        $this->assertCount(1, $items);
        $this->assertSame('Sally', $items[0]['actor']['label']);
        $this->assertSame(['type' => 'project', 'id' => '2', 'label' => 'Storyfeed'], array_intersect_key($items[0]['object'], array_flip(['type', 'id', 'label'])));
        $this->assertSame('https://teylabs.com/projects/storyfeed', $items[0]['object']['link']['href']);
    }

    #[Test]
    public function it_schedules_nothing(): void
    {
        $storyfeed = array_filter(
            app(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'storyfeed:'),
        );

        $this->assertSame([], $storyfeed);
    }

    #[Test]
    public function recording_throws_the_mode_exception(): void
    {
        $this->expectException(StorageDisabled::class);
        $this->expectExceptionMessage('Storyfeed::withoutStorage()');

        Storyfeed::activity('ship')->actor('Tey Labs')->publish();
    }

    #[Test]
    public function the_helper_throws_it_too(): void
    {
        $this->expectException(StorageDisabled::class);

        storyfeed('ship', new Project(['id' => 1, 'name' => 'Storyfeed']));
    }

    #[Test]
    public function a_feedable_model_saves_and_deletes(): void
    {
        $trait = StoragelessProject::create(['name' => 'Storyfeed']);
        $trait->update(['name' => 'Storyfeed core']);

        Storyfeed::feedable(RegisteredProject::class)
            ->toFeedUsing(fn (RegisteredProject $project, FeedEntity $entity) => $entity->label($project->name));

        $registered = RegisteredProject::create(['name' => 'TalkingFeed']);
        $registered->delete();
        $trait->delete();

        $this->assertSame(0, StoragelessProject::query()->count());
    }

    #[Test]
    public function doctor_reports_the_mode_and_skips_the_tables(): void
    {
        $report = Storyfeed::doctor();

        $this->assertTrue($report->has('recording.without_storage'));
        $this->assertFalse($report->has('recording.disabled'));
        $this->assertFalse($report->has('tables.missing'));
        $this->assertSame([], $report->withCode('doctor.check_failed')->map->message->all());
    }
}
