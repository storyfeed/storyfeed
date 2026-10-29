# Adoption inventory

`surfaces.json` describes the backend side of an activity feed in format 1.
The schema is copied unchanged from tey/kit
commit `594f71c27fc40b964dd966a8c07aaf29310b9cdd`. Every shipped PHP entry point
belongs to a surface or has an explicit non-surface reason. The inventory
classifies adoption statically; it does not prove runtime behaviour.

Run the coverage and outcome checks with:

```sh
vendor/bin/pest tests/AdoptionSurfacesTest.php
```

`tey/standards` is deliberately not a dev dependency: version 0.11.46
requires `illuminate/config ^13.0` and PHP `^8.4.1`, conflicting with core's
Laravel 12 support. Coverage checks run without it. CLI outcome checks skip
when the checker is absent; run them with an isolated installation:

```sh
STORYFEED_ADOPTION_CHECKER=/path/to/isolated/vendor/bin/standards \
    vendor/bin/pest tests/AdoptionSurfacesTest.php
```

The four app trees live in `tests/Fixtures/adoption`. `expected.json` contains
all five outcomes for each app. The test runs `vendor/bin/standards adoption
--base <app> --inventory resources/adoption/surfaces.json --json` and compares
the facts and rows. The compatibility apps install Storyfeed but retain their
own implementation: standards reports an absent package as `missing` even
when hand-rolled signals fire. They include an activity timeline to establish
the shared `has-feeds` fact independently of their backend implementation.

## Shared applicability

`has-feeds` retains the kit's id, description and computed frontend signals.
Storyfeed additionally contributes PHP classes extending `Story` or `Feed`,
or implementing `Feedable` or `PublishesToFeed`, as evidence of the same need.
The fact is declared here because standards keys facts by package. No fact
reads another package's inventory and no exemption is used.

## Format 1 limits

- `import` signals inspect frontend imports. PHP facts can detect a class's
  parent or implemented interfaces, but cannot detect a lone facade import or
  a `story()` / `storyfeed()` call. PHP classes using only those entry points
  still need one of the shared frontend signals to establish `has-feeds`.
- `uses.classes` detects references, not individual calls. A `Feed` reference
  is evidence for querying and context partitioning, not proof of a context
  constraint. Explicit `FeedBuilder::context()` scoping remains app-owned;
  this is not automatic tenant isolation or an authorization check.
- The shared fact establishes feed need for all five surfaces. It cannot
  separately establish whether an app needs tenant partitions or customised
  storage configuration. No overlapping applicability fact is invented here.
- `file-name` signals inspect frontend and Blade files. PHP custom query
  detection therefore uses `php-class.names` (`*ActivityFeed*`, `*Timeline*`).
  Widget patterns match the kit's `feed.activity` hand-rolled patterns.
- The shared frontend signals do not by themselves detect a backend-only
  Spatie dependency or activity-log migration. Such an app can read `n/a`
  without a recognised page or Storyfeed PHP declaration. Broadening this
  shared fact requires coordination with the kit rather than a local fork.
- Table signals detect literal `Schema::create` calls for `activity_log`,
  `activities` or `events`; arbitrary names and runtime-created tables are
  outside their static evidence. A domain table called `events` may need
  review. Schema signals do not prove snapshot semantics.
