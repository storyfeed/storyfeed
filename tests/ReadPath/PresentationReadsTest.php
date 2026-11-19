<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * A PAGE READS EACH ATTRIBUTE ONCE (#268, 2026-11-19).
 *
 * Presenting a node read `verb`, `data` and `published_at` through
 * Eloquent again and again, and each read paid for its cast bookkeeping.
 * Now a page reads each of an activity's attributes once, raw where the
 * column has no cast or accessor and through the model otherwise, so an
 * app's accessor still shapes what the payload carries. Role identities
 * keep their own fresh reader (roleFields()).
 */

class CountingActivity extends Activity
{
    /** @var array<string, array<string, int>> reads by activity id, then key */
    public static array $reads = [];

    protected $table = 'feed_activities';

    public function getAttribute($key)
    {
        $id = (string) ($this->attributes['id'] ?? '');
        self::$reads[$id][$key] = (self::$reads[$id][$key] ?? 0) + 1;

        return parent::getAttribute($key);
    }

    /** @return array<string, mixed> */
    public function getDataAttribute(?string $value): array
    {
        return [...(json_decode($value ?? '[]', true) ?: []), 'shaped' => true];
    }
}

it('reads each activity attribute through a custom model once per page, accessors included', function () {
    $actor = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $case = Customer::create(['name' => 'Case']);
    foreach (range(1, 4) as $i) {
        Storyfeed::activity(['ship', 'confirm'][$i % 2], Delivery::create(['customer_id' => $case->id]))
            ->actor($actor)->target($case)->publishedAt(now()->subMinutes(10 - $i))->publish();
    }
    config()->set('storyfeed.models.activity', CountingActivity::class);
    CountingActivity::$reads = [];

    $payload = json_decode((string) json_encode(Storyfeed::feed()->involving($case)->cursorPaginate(10)), true);

    $nodes = collect($payload['data'])->flatMap(fn (array $node) => $node['kind'] === 'group' ? $node['children'] : [$node]);
    expect($nodes)->toHaveCount(4)
        ->and($nodes->pluck('data.shaped')->unique()->all())->toBe([true])
        ->and(CountingActivity::$reads)->toHaveCount(4);

    foreach (CountingActivity::$reads as $reads) {
        foreach (['verb', 'uid', 'data', 'published_at'] as $key) {
            expect($reads[$key] ?? 0)->toBe(1, "{$key} read ".($reads[$key] ?? 0).' times');
        }
    }
});
