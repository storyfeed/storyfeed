<?php

namespace Storyfeed\Tests\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\SchemaState;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Delivery;

/**
 * An install from before 0.23 that keeps its tables instead of rebuilding
 * (#60). Its published config names the table under the old `parties` key
 * and pins the old alias, so its rows keep reading and new rows join them
 * under the same alias.
 */
class KeptPartiesTableTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // The config that install published: no `entities` key.
        $tables = $app['config']->get('storyfeed.tables');
        unset($tables['entities']);
        $app['config']->set('storyfeed.tables', [...$tables, 'parties' => 'feed_parties']);
        $app['config']->set('storyfeed.morph_alias', 'storyfeed.party');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The table it kept was created before `kind` existed.
        if (Schema::hasColumn('feed_parties', 'kind')) {
            Schema::table('feed_parties', fn ($table) => $table->dropColumn('kind'));
        }
    }

    #[Test]
    public function it_reads_the_kept_table_under_the_old_key(): void
    {
        $this->assertSame('feed_parties', SchemaState::table('entities'));
        $this->assertSame('feed_parties', (new Party)->getTable());
        $this->assertFalse(Schema::hasTable('feed_entities'));
    }

    #[Test]
    public function it_reads_legacy_rows_and_finds_them_by_involving(): void
    {
        // A row written before the upgrade, with no kind column to fill.
        DB::table('feed_parties')->insert(['key' => 'concur', 'name' => 'Concur', 'type' => 'Service', 'created_at' => now(), 'updated_at' => now()]);
        $legacy = Party::query()->where('key', 'concur')->sole();

        $this->assertSame('party', $legacy->kind);
        $this->assertSame('storyfeed.party', $legacy->getMorphClass());

        Storyfeed::activity()->actor($legacy)->verb('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

        // Party::make() still writes the kept table, which has no kind.
        $made = Party::make('Concur');
        $this->assertTrue($made->is($legacy));

        $row = DB::table('feed_activities')->sole();
        $this->assertSame('storyfeed.party', $row->actor_type);

        $found = Storyfeed::feed()->involving($legacy)->get()->toArray();
        $this->assertCount(1, $found);

        $activity = Activity::query()->sole();
        $this->assertTrue($activity->actor->is($legacy));
    }
}
