<?php

use Illuminate\Support\Carbon;
use Storyfeed\Diagnostics\Checks\AggregateTokens;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\StoryfeedManager;

it('preserves every legacy field combination with promoted roles absent and filled', function () {
    // Independent pre-W86 canonical vocabulary, deliberately not Field constants.
    $legacy = ['aa' => 'user', 'aid' => '7', 'v' => 'revise', 'oa' => 'file',
        'oid' => '42', 'ta' => 'folder', 'tid' => '9', 'ca' => 'workspace',
        'cid' => '3', 'd' => '2026-09-08'];
    $activity = new Activity([
        'actor_type' => 'user', 'actor_id' => 7, 'verb' => 'revise',
        'object_type' => 'file', 'object_id' => 42, 'target_type' => 'folder', 'target_id' => 9,
        'context_type' => 'workspace', 'context_id' => 3,
        'published_at' => Carbon::parse('2026-09-08 12:00:00'),
    ]);
    foreach (range(1, 1023) as $mask) {
        $parts = [];
        foreach (array_keys($legacy) as $bit => $token) {
            if (($mask & (1 << $bit)) !== 0) {
                $parts[$token] = $legacy[$token];
            }
        }
        $axis = Axis::make('legacy')->key(implode(':', array_reverse(array_keys($parts))));
        $expected = implode(':', $parts);
        expect($axis->hashFor($activity))->toBe($expected);
        $filled = clone $activity;
        foreach (['origin', 'result', 'instrument'] as $role) {
            $filled->setAttribute($role.'_type', 'party');
            $filled->setAttribute($role.'_id', 99);
        }
        expect($axis->hashFor($filled))->toBe($expected);
    }
});

it('derives new role pins required fields and doctor findings from the recipe', function (string $role, string $type, string $id) {
    $axis = Axis::make('promoted')->key("{$type}!:{$id}!:d");
    $activity = new Activity([$role.'_type' => 'party', $role.'_id' => 7,
        'published_at' => Carbon::parse('2026-09-08')]);
    expect($axis->hashFor($activity))->toBe('party:7:2026-09-08')
        ->and($axis->requiredRoles())->toBe([$role])
        ->and($axis->pinsType($role))->toBeTrue()
        ->and($axis->pinnedTokens())->toContain(':'.$role)
        ->and(Axis::make('half')->key($type.':d')->pinnedTokens())->not->toContain(':'.$role);
    $activity->setAttribute($role.'_id', null);
    expect($axis->hashFor($activity))->toBeNull();

    Storyfeed::axes([$axis]);
    Storyfeed::aggregateGrammar(['promoted.confirm' => ':'.$role, 'repeat.confirm' => ':'.$role]);
    $findings = collect((new AggregateTokens)->run(app(StoryfeedManager::class)));
    expect($findings->filter(fn ($f) => $f->code === 'tokens.unpinned')->map(fn ($f) => $f->subject['key'])->values()->all())
        ->toBe(['repeat.confirm']);
})->with([['origin', 'ora', 'orid'], ['result', 'ra', 'rid'], ['instrument', 'ia', 'iid']]);

it('exposes pinned group entities and refuses to attribute mixed provenance to one entity', function (string $role, string $type, string $id) {
    Storyfeed::axes([Axis::make('promoted')->key("{$type}:{$id}:d")]);
    Storyfeed::aggregateGrammar(['promoted.confirm' => ':'.$role]);
    $one = Storyfeed::activity('confirm')->{$role}('First')->publish();
    $two = Storyfeed::activity('confirm')->{$role}('First')->publish();
    $presenter = app(NodePresenter::class);
    $node = $presenter->groupNode(GroupSlice::group('promoted', 'test', 2, collect([$one, $two])));
    expect($node[$role]['label'])->toBe('First')
        ->and($node['exemplars'][$role.'s'])->toHaveCount(1)
        ->and($node['distinct'][$role.'s'])->toBe(1);

    // A misdeclared custom pin must not name the loaded exemplar for unseen members.
    $mixed = $presenter->groupNode(GroupSlice::group('promoted', 'test', 20, collect([$one, $two]), [$role => 8]));
    expect($mixed[$role])->toBeNull()
        ->and($mixed['distinct'][$role.'s'])->toBe(8)
        ->and($mixed['children'][0][$role]['label'])->toBe('First');
})->with([['origin', 'ora', 'orid'], ['result', 'ra', 'rid'], ['instrument', 'ia', 'iid']]);

it('counts promoted roles beyond capped children on an existing live group', function () {
    config(['storyfeed.grouping.children_limit' => 2]);
    foreach (range(1, 5) as $i) {
        Storyfeed::activity('confirm')->actor('Operator')->origin('Source '.$i)
            ->result('Output '.$i)->instrument('Tool '.$i)->publish();
    }
    $items = Storyfeed::feed()->live()->get()->items();
    expect($items)->toHaveCount(1);
    $group = $items[0];
    expect($group['kind'])->toBe('group')->and($group['children'])->toHaveCount(2);
    foreach (['origin', 'result', 'instrument'] as $role) {
        expect($group[$role])->toBeNull()
            ->and($group['distinct'][$role.'s'])->toBe(5)
            ->and($group['exemplars'][$role.'s'])->toHaveCount(2)
            ->and($group['children'][0][$role])->not->toBeNull();
    }
});
