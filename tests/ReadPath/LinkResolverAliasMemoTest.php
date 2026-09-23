<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Support\LinkResolver;
use Workbench\App\Models\Customer;

/*
 * THE ALIAS MEMO LIVES AND DIES WITH ITS SCOPE (todo 1338, 2026-09-23).
 *
 * LinkResolver resolves each alias once per scope rather than once per
 * entity. Swapping what an alias names between two asks is the only way to
 * see that from outside: the same scope keeps its first answer, a fresh
 * scope — the next page — reads the morph map again.
 */

class MemoFirstCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make(label: "first {$context->key()}");
    }
}

class MemoSecondCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make(label: "second {$context->key()}");
    }
}

it('resolves an alias once per scope, and never carries it into the next', function () {
    Relation::morphMap(['memo_swap' => MemoFirstCustomer::class]);

    $links = new LinkResolver;

    expect($links->resolve(new FeedContext(type: 'memo_swap', key: 1))?->label)->toBe('first 1');

    Relation::morphMap(['memo_swap' => MemoSecondCustomer::class]);

    // Same scope: the alias was answered already. The media is still per
    // entity — only the class behind the alias is remembered.
    expect($links->resolve(new FeedContext(type: 'memo_swap', key: 2))?->label)->toBe('first 2')
        ->and((new LinkResolver)->resolve(new FeedContext(type: 'memo_swap', key: 3))?->label)->toBe('second 3');
});

it('remembers an alias that names nothing, and still degrades it to null', function () {
    $links = new LinkResolver;

    expect($links->resolve(new FeedContext(type: 'memo_nothing', key: 1)))->toBeNull()
        ->and($links->resolve(new FeedContext(type: 'memo_nothing', key: 2)))->toBeNull();
});
