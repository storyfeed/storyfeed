<?php

/*
 * Generates the docs world's feeds through core, the way an app gets them
 * (storyfeed/docs#41). For each world pack in a storyfeed/docs checkout, it
 * records every row into an empty in-memory SQLite database through real
 * models (Workbench\App\Docs), and reads them back with `->log()` and
 * `->live()`.
 *
 *   php workbench/docs/generate.php <docs path>                write nothing, print the report
 *   php workbench/docs/generate.php <docs path> --out=<dir>    write <dir>/<pack>/payloads.json and scenes.json
 *   php workbench/docs/generate.php <docs path> --check        exit 1 when anything differs from today
 *   php workbench/docs/generate.php --world=<json> --out=<dir>  generate from an exported world, with no docs
 *                                                              checkout and no report (the test suite's fixture)
 *
 * payloads.json is the file the docs commit beside each pack: every row's Log
 * node, keyed by row id. scenes.json is new: each named scene read in Live,
 * recorded on its own, and the rows a page builds by hand today (a deletion,
 * a composite).
 *
 * The report compares payloads.json with the docs' committed file, and each
 * scene's Live page with the array source's (scripts/payloads/read.php), and
 * says what kind of change each difference is.
 *
 * The world is read from the docs' TypeScript by export-world.mjs, so Node 22.15
 * or later must be on the path (or at NODE_BINARY).
 */

use Workbench\App\Docs\Comparison;
use Workbench\App\Docs\Generator;

require __DIR__.'/../../vendor/autoload.php';

$docs = null;
$options = [];
foreach (array_slice($_SERVER['argv'], 1) as $argument) {
    if (preg_match('/^--(out|check|world)(?:=(.*))?$/', $argument, $option) === 1) {
        $options[$option[1]] = $option[2] ?? true;
    } else {
        $docs = $argument;
    }
}

if (isset($options['world'])) {
    $export = file_get_contents($options['world']);
} elseif ($docs !== null && is_dir("{$docs}/docs/.vitepress/theme/worlds")) {
    $export = shell_exec(implode(' ', array_map(escapeshellarg(...), [getenv('NODE_BINARY') ?: 'node', __DIR__.'/export-world.mjs', $docs])));
} else {
    fwrite(STDERR, "Pass the path to a storyfeed/docs checkout, or --world=<exported world JSON>.\n");
    exit(2);
}

$packs = json_decode((string) $export, true, flags: JSON_THROW_ON_ERROR);

/** JSON as the docs write it: JSON.stringify(value, null, 2) and a newline. */
$encode = fn (mixed $value): string => preg_replace_callback(
    '/^ +/m',
    fn (array $indent) => substr($indent[0], 0, intdiv(strlen($indent[0]), 2)),
    json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS),
)."\n";

$differs = false;

// Each read boots an app whose exception handler reports an uncaught
// exception and exits 0, so a failed run would look like a clean one.
try {
    foreach ($packs as $name => $pack) {
        $generator = new Generator($pack);
        $payloads = $generator->payloads();
        $scenes = ['live' => $generator->live(), 'variants' => $generator->variants()];

        if (isset($options['out'])) {
            @mkdir("{$options['out']}/{$name}", recursive: true);
            file_put_contents("{$options['out']}/{$name}/payloads.json", $encode($payloads));
            file_put_contents("{$options['out']}/{$name}/scenes.json", $encode($scenes));
        }

        if ($docs === null) {
            continue;
        }

        $file = "{$docs}/docs/.vitepress/theme/worlds/{$name}/payloads.json";
        $committed = is_file($file) ? (string) file_get_contents($file) : '{}';

        $comparison = new Comparison($name, $pack);
        $comparison->payloads($payloads, json_decode($committed, true, flags: JSON_THROW_ON_ERROR), $encode($payloads) === $committed);
        $comparison->live($scenes['live'], $generator->liveFromArrays());
        $comparison->variants($scenes['variants']);

        echo $comparison->report();
        $differs = $differs || $comparison->differs();
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.": {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}

exit(isset($options['check']) && $differs ? 1 : 0);
