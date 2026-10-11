# The docs generator

Every feed example on docs.storyfeed.dev is this package's own output
(storyfeed/docs#41). `generate.php` reads a storyfeed/docs checkout's world
packs (through `export-world.mjs`), records each row into an empty in-memory
SQLite database through real models (`Workbench\App\Docs`), and reads them back
with the calls the docs teach. Per pack it writes:

- `payloads.json`: every row's node, read with `->log()`, keyed by row id;
- `scenes.json`: each named scene read with `->live()`, each recorded in its
  own database, and the rows a page builds by hand (a real deletion, read as a
  tombstone, and a real composite).

The docs commit both files and run this through `npm run payloads`, which
needs PHP, Node 22.15 or later, and this checkout with Composer's install
(`STORYFEED_CORE`). The docs deploy regenerates them against core's `main` and
fails when they differ. `php workbench/docs/generate.php <docs path>` prints
how the output differs from the docs' committed files; the options are in
`generate.php`.

## Rows are recorded in time order

Live assigns bursts as activities arrive, so the same rows published in a
different order can group differently. Published in pack order, `everything`
lost four of its groups. The generator records oldest first, with rows at one
instant in pack order, which is how an app records them. An app that backfills
history out of order has to rebuild its bursts with
`storyfeed:curate --rebuild-bursts`.
