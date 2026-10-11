<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$root = realpath($argv[1]);
if (! $root || ! str_contains($root, '/rnd-autoconfig-apps/') || basename($root) === 'baseline') {
    throw new RuntimeException('Copies only; baseline is immutable.');
}
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$changes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || $file->isLink()) {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    $ast = $parser->parse($code);
    $cuts = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        if ($method->name->toString() === 'toFeedActivity') {
            $cuts[] = [$method->getStartFilePos(), $method->getEndFilePos()];
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Stmt\Expression::class) as $stmt) {
        $text = substr($code, $stmt->getStartFilePos(), $stmt->getEndFilePos() - $stmt->getStartFilePos() + 1);
        // Only activity chains, not unrelated application methods named publish.
        if (str_contains($text, '->publish(') && preg_match('/PendingActivity|story\(|storyfeed\(|Storyfeed::|Act::|toFeedActivity\(/', $text)) {
            $cuts[] = [$stmt->getStartFilePos(), $stmt->getEndFilePos()];
        }
    }
    usort($cuts, fn ($a, $b) => $a[0] <=> $b[0]);
    $outer = [];
    foreach ($cuts as $cut) {
        if ($outer === [] || $cut[0] > $outer[count($outer) - 1][1]) {
            $outer[] = $cut;
        }
    }
    foreach (array_reverse($outer) as [$start, $end]) {
        $code = substr_replace($code, '', $start, $end - $start + 1);
    }
    $code = preg_replace('/,\s*PublishesToFeed\b|PublishesToFeed\s*,\s*/', '', $code);
    $code = preg_replace('/implements\s+PublishesToFeed\s*\n/', "\n", $code);
    if ($code !== file_get_contents($file->getPathname())) {
        file_put_contents($file->getPathname(), $code);
        $changes[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
echo json_encode($changes, JSON_PRETTY_PRINT)."\n";
