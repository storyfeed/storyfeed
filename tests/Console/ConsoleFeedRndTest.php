<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\Console\FeedRenderer;

require_once __DIR__.'/../../workbench/console/FeedRenderer.php';

beforeEach(function () {
    $this->artisan('storyfeed:demo', ['--days' => 3, '--seed' => 1])->assertExitCode(0);
});

it('keeps all supplied demo nodes and child activities visible when expanded', function () {
    $page = Storyfeed::feed()->summary()->limit(100)->get()->toArray();
    $renderer = new FeedRenderer('ascii', expanded: true);
    $out = $renderer->render($page, 200);
    $nodes = $page['items'];
    $groups = 0;
    foreach ($page['items'] as $node) {
        if ($node['kind'] === 'group') {
            $groups++;
            $nodes = [...$nodes, ...$node['children']];
        }
    }
    expect($groups)->toBeGreaterThan(0);
    foreach ($nodes as $node) {
        $headline = implode('', array_column($renderer->headline($node), 0));
        expect($out)->toContain($headline);
    }
    expect(substr_count($out, '+------------------------------'))->toBe(count($nodes));
});

it('preserves noun spacing through the Termwind HTML and console formatter layers', function () {
    $page = Storyfeed::feed()->summary()->limit(20)->get()->toArray();
    $renderer = new FeedRenderer('termwind');
    $out = str_replace("\u{00a0}", ' ', $renderer->render($page, 200, false));
    foreach ($page['items'] as $node) {
        expect($out)->toContain(implode('', array_column($renderer->headline($node), 0)));
    }
    expect($out)->not->toContain("\033");
});

it('wraps demo row bodies at narrow widths without losing headline characters', function () {
    $page = Storyfeed::feed()->summary()->limit(8)->get()->toArray();
    $renderer = new FeedRenderer('ascii');
    $out = $renderer->render($page, 24);
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, '|') || str_starts_with($line, '+')) {
            expect(mb_strwidth($line))->toBeLessThanOrEqual(24);
        }
    }
});
