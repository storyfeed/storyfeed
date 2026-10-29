# Adoption inventory

`surfaces.json` describes the backend side of an activity feed in format 1.
The schema is copied unchanged from tey/kit
commit `594f71c27fc40b964dd966a8c07aaf29310b9cdd`. Every shipped PHP entry point
belongs to a surface or has an explicit non-surface reason. The inventory
classifies adoption statically; it does not prove runtime behaviour.

Run the coverage and outcome checks with:

```sh
composer install -d tools/standards
vendor/bin/pest tests/AdoptionSurfacesTest.php
```

`tools/standards/composer.json` requires only `tey/standards ^0.11.46`.
Its dependencies stay isolated from core: standards requires Illuminate 13,
while core also supports Laravel 12. The tests prefer the isolated executable
when present, then fall back to `vendor/bin/standards`. CLI cases skip when
neither exists. To select another isolated installation explicitly:

```sh
STORYFEED_ADOPTION_CHECKER=/path/to/isolated/vendor/bin/standards \
    vendor/bin/pest tests/AdoptionSurfacesTest.php
```

The separate **Adoption fixtures (PHP 8.4)** CI job installs the isolated
checker and explicitly selects it, so a missing binary fails instead of
skipping the five CLI cases. The test runs
`tools/standards/vendor/bin/standards adoption --base <app>
--inventory resources/adoption/surfaces.json --json` for each app and compares
all five surfaces' facts and rows with `tests/Fixtures/adoption/expected.json`.

The five app trees cover adopted Storyfeed APIs, Spatie with a timeline,
custom migration/query code with a timeline, no feed or log, and a backend-only
activity-log migration with no feed page. Compatibility apps install Storyfeed
but retain their own implementation: standards reports an absent package as
`missing` even when hand-rolled signals fire. The backend-only app reports
`has-feeds: false` and `has-activity-log: true`; recording, snapshots and storage
configuration are `hand-rolled`, while query and context are `missing`.

## Applicability

`has-feeds` is unchanged: it retains the kit's id, description and computed
frontend signals, plus the existing Storyfeed PHP evidence (classes extending
`Story` or `Feed`, or implementing `Feedable` or `PublishesToFeed`).

The separate `has-activity-log` fact detects activity/audit dependencies
(Spatie Activitylog or Laravel Auditing), activity-log PHP class declarations,
or app-owned migrations creating `activity_log`, `activities` or `events`.
It also includes the existing ActivityStream import and Storyfeed declaration
signals, so adopting the package continues to establish the need. Merely
installing Storyfeed does not make the fact true.

All five backend surfaces apply when **either** fact holds. A backend-only log
therefore establishes applicability without broadening the shared page fact.
Facts are declared here because standards keys them by package. No fact reads
another package's inventory and no exemption is used.

## Format 1 limits

- `import` signals inspect frontend imports. PHP facts can detect a class's
  parent, implemented interfaces or short name, but cannot detect a lone facade
  import or a `story()` / `storyfeed()` call. Those calls alone do not establish
  applicability without another recognised feed or log signal.
- `uses.classes` detects references, not individual calls. A `Feed` reference
  is evidence for querying and context partitioning, not proof of a context
  constraint. Explicit `FeedBuilder::context()` scoping remains app-owned;
  this is not automatic tenant isolation or an authorization check.
- Feed or activity-log need applies to all five surfaces. Neither fact separately
  establishes whether an app needs tenant partitions or customised storage.
- `file-name` signals inspect frontend and Blade files. PHP custom query
  detection therefore uses `php-class.names` (`*ActivityFeed*`, `*Timeline*`).
  Widget patterns match the kit's `feed.activity` hand-rolled patterns.
- Table signals detect literal `Schema::create` calls for `activity_log`,
  `activities` or `events`; arbitrary names and runtime-created tables are
  outside their static evidence. A domain table called `events` or a matching
  PHP class name may need review. Schema signals do not prove snapshot semantics.
- Applicability evidence is separate from each surface's hand-rolled signals.
  Recognising an audit dependency does not prove how it implements each surface.
