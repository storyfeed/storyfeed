<?php

// Independent transport experiment, NOT a seeded feed row or a payload fixture.
// Default prints measurements only. --emit requires a real TTY.
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/FeedRenderer.php';
require __DIR__.'/RasterProbe.php';

use Workbench\Console\FeedRenderer;
use Workbench\Console\RasterProbe;

$url = $argv[1] ?? 'https://images-assets.nasa.gov/image/PIA18033/PIA18033~small.jpg';
$emit = $argv[2] ?? null;
$image = ['src' => $url, 'alt' => 'Independent graphics transport probe; not feed data'];
foreach (['iterm2', 'kitty'] as $protocol) {
    $renderer = new FeedRenderer('termwind', $protocol);
    $start = hrtime(true);
    $out = $renderer->media($image, 'preview', 80);
    $cold = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $warm = $renderer->media($image, 'preview', 80);
    $warmMs = (hrtime(true) - $start) / 1e6;
    if (! str_contains($out, "\033")) {
        fwrite(STDERR, $out);
        exit(1);
    }
    $decoded = '';
    if ($protocol === 'kitty') {
        preg_match_all('/\x1b_G([^;]+);([^\x1b]+)\x1b\\\\/', $out, $matches, PREG_SET_ORDER);
        foreach ($matches as $part) {
            $decoded .= base64_decode($part[2], true);
            if (strlen($part[2]) > 4096) {
                throw new RuntimeException('Chunk too large');
            }
        }
        $chunks = count($matches);
        if (! str_contains($matches[array_key_last($matches)][1], 'm=0')) {
            throw new RuntimeException('Missing final chunk');
        }
    } else {
        preg_match('/\x1b\]1337;File=[^:]+:([^\x07]+)\x07/', $out, $matches);
        $decoded = base64_decode($matches[1], true);
        $chunks = 1;
    }
    if (getimagesizefromstring($decoded)['mime'] !== 'image/png') {
        throw new RuntimeException('Round-trip not PNG');
    }
    file_put_contents(__DIR__.'/../../build/w93-'.$protocol.'.ansi', $out);
    printf("%s: cold fetch+convert+encode=%.2f ms; warm=%.3f ms; wire=%d bytes; chunks=%d; decoded PNG=%d bytes; framing PASS\n", $protocol, $cold, $warmMs, strlen($out), $chunks, strlen($decoded));
    if ($emit === '--emit='.$protocol && stream_isatty(STDOUT)) {
        echo $out;
    }
}

foreach (['blocks', 'sixel'] as $mode) {
    $start = hrtime(true);
    $out = RasterProbe::encode($decoded, $mode, 32);
    $ms = (hrtime(true) - $start) / 1e6;
    file_put_contents(__DIR__.'/../../build/w93-'.$mode.'.ansi', $out);
    printf("%s: decode+resample+encode=%.2f ms; wire=%d bytes; no terminal paint verified\n", $mode, $ms, strlen($out));
    if ($emit === '--emit='.$mode && stream_isatty(STDOUT)) {
        echo $out;
    }
}
