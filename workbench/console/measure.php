<?php

// Measure renderer-only cost from an actual `storyfeed:console --json` capture.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/FeedRenderer.php';

use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\Console\FeedRenderer;

$page = json_decode(file_get_contents($argv[1] ?? __DIR__.'/../../build/w93-payload.json'), true, flags: JSON_THROW_ON_ERROR);
putenv('COLORTERM=truecolor');
printf("PHP %s; %d real head nodes; 100 renders at 80 columns; no DB or network in timed region\n", PHP_VERSION, count($page['items']));
foreach (['ascii', 'box', '256', 'truecolor', 'termwind'] as $rung) {
    $renderer = new FeedRenderer($rung);
    $samples = [];
    for ($i = 0; $i < 100; $i++) {
        $start = hrtime(true);
        $out = $renderer->render($page, 80, true);
        $samples[] = (hrtime(true) - $start) / 1e6;
    }
    sort($samples);
    printf("%-10s median=%6.3f ms p95=%6.3f ms bytes=%d SGR=%d RGB=%d\n", $rung, $samples[50], $samples[94], strlen($out), substr_count($out, "\033["), substr_count($out, '38;2;'));
    file_put_contents(__DIR__.'/../../build/w93-'.$rung.'.ansi', $out);
}
$buffer = new BufferedOutput;
\Termwind\renderUsing($buffer);
\Termwind\render('<div>before<img src="https://example.test/photo.png" alt="photo">after</div>');
printf("Termwind img element: %s\n", json_encode($buffer->fetch()));
\Termwind\renderUsing(null);
