# Body Migration Prototype

Run the simulations and benchmark from this checkout:

```sh
vendor/bin/pest tests/BodyMigrations
```

`BenchmarkTest` writes engine-specific measurements to the ignored
`build/body-benchmark-{sqlite,pgsql}.json` files. It compares raw and normalized
50-item pages, before and after rewriting 5,100 activities. Each page contains
600 body occurrences across activity data, snapshot data, and snapshot body
lists. Party source data is also rewritten so later snapshot refreshes preserve
the current shape. Measurements include database queries and presentation, use one warm-up
and 30 samples, and report the median. The rewrite uses 500-row transactions.

For PostgreSQL, use a disposable database: Testbench recreates its tables.

```sh
STORYFEED_TEST_DB=pgsql STORYFEED_TEST_HOST=127.0.0.1 \
STORYFEED_TEST_DATABASE=storyfeed_w65_body_proof \
STORYFEED_TEST_USERNAME=postgres vendor/bin/pest tests/BodyMigrations
```

The durable-interruption test uses a separate SQLite file inside `build`, kills
its worker with `SIGKILL` after the fifth committed chunk, and resumes from
another connection. It skips on non-POSIX systems and other database engines.
The separate mixed-history test inserts current-version rows between chunks.
These tests do not establish behaviour under overlapping writers updating the
same row, multiple rewrite workers, or a rolling deployment with old writers.

## Laravel Precedent

The prototype reuses Laravel 13.24.0's
`Illuminate/Database/Migrations/MigrationCreator.php`: `create()`,
`getCollisionFreePath()`, `getDatePrefix()`, `populateStub()` and its hooks.
`BodyMigrationCreator` supplies the body stub. Discovery calls
`Migrator::getMigrationFiles()` directly, preserving timestamped filename
ordering. An additional guard rejects duplicate filenames across paths,
rather than letting Laravel's keyed collection silently choose one.

The existing KeyValue and MediaObject conversions are their first migrations.
Released versions remain 2. Normalization stays on the body classes; the
migration contains the historical conversion.

## Guards and Fallback

Core bodies use Laravel JSON Schema declarations and committed snapshots.
Application examples supply a field-name shape when no schema is available.
The validator deliberately supports a subset, rejects unsupported keywords,
and is not a general JSON Schema implementation. In particular, `format` is
not supported. Schemas and examples cannot detect same-shape semantic changes.

History snapshots contain ordered filenames and content hashes. Compare a
merge with **both** branch snapshots as well as the released baseline. A
common-ancestor snapshot alone cannot detect conflicting unpublished version
positions. CI baseline selection and release automation remain integration
work; the helper and tests prove the comparison, not a hosted release gate.

`Storyfeed/Body/Unreadable` is an emitted response body, never a stored
replacement. UI and Filament adapters need to map it to an accessible visible
placeholder. Raw mode remains unchanged; the core placeholder path is opt-in.
A deployment must enable normalization and ship compatible renderers to get
the complete user experience. No adapter files are part of this lane.

The eager path stays in PHP. A set-based SQL implementation needs equivalent
handling for arbitrary nested locations, arrays, null versus absent fields,
body versions, failures and closures on SQLite and PostgreSQL. No SQL compiler
or automatic `php artisan migrate` hook is included. A future hook can listen
after database migrations, or dispatch the command as a deployment job.
