# Changelog

## Unreleased — v0.7 (authoring DX)

### Added

- **`Storyfeed\Story`** — one class per activity type, compiling down into the
  existing registries. Authoring one activity type went from seven places to one
  file. Ad-hoc forms (`StoryDefinition`, `'type.verb' => [...]`) for cases where a
  class is ceremony. See `docs/stories.md` and `docs/upgrading-to-stories.md`.
- **`make:story`** — generates a Story, reading the object and verb from the
  `{Object}Was{Verbed}` convention and writing both into the file. `--from-doctor`
  scaffolds one per unauthored pair doctor observed.
- **`Contracts\PublishesToFeed`** — domain events publish to the feed via one
  interface-registered listener. See `docs/publishing-from-events.md`.
- **`Storyfeed::doctor(): Report`** — findings as data. `storyfeed:doctor` gains
  `--json`, `--stubs` (paste-ready registrations), `--only=`, `--list` and
  `--fail-on=`. `Storyfeed::checks([...])` registers app checks.
- **`storyfeed:stories`** — the publish-site inventory: what publishes, and what
  is declared but does not.
- **`storyfeed:cache` / `storyfeed:clear`**, registered with `php artisan
  optimize` and `optimize:clear`.
- **`GrammarCoverage::assertCoversPossibleAggregates()`** and
  **`StorySurface::assertNoUnwiredSurface()`**.
- `Axis::requiredRoles()` / `appliesToRoles()`, `StoryfeedManager::axesApplicableTo()`
  and `possibleAggregatePairs()` — axis applicability derived from the compiled
  recipes rather than reasoned about.
- New doctor checks: `freshness` (nothing published in N days), `surface`
  (declared surface that publishes nothing), `manifest` (a stale story cache).
- `storyfeed.grammar.strict` — publishing a `(type, verb)` with no headline throws
  in local/testing, like `verbs.strict`. Production is never gated.

### Changed

- `storyfeed:verbs --used` now renders the same `VerbDrift` check doctor uses, so
  the two commands cannot disagree.
- `verbs.undeclared` warns only once an app has declared a vocabulary, and dead
  vocabulary is reported only for app-declared verbs — reporting the 29 shipped
  defaults buried the signal.


All notable changes to `storyfeed` will be documented in this file.
