# Changelog

## v0.8.0-alpha.1 — audience scoping (2026-08-18)

The first tag since `v0.6.0-alpha.1`. It carries two milestones: the v0.7
authoring-DX work, which was written but never tagged, and v0.8 audience
scoping. There is no separate v0.7 tag — the work is in this one.

### Added — audience scoping (v0.8)

- **`Storyfeed::feeds([...])`** — named feed presets, declared once at boot in the
  same shape as grammar/axes/verbs/icons/stories/checks. Entered via
  `Storyfeed::feed('customer')` and `$model->storyfeed('customer')`; an unknown
  name throws `Exceptions\UnknownFeed` rather than falling back silently.
- **`only()` / `except()`** on any `FeedBuilder` — verb allow/denylists taking
  strings, `FeedVerb` cases and backed enums in one list, with trailing-`*`
  prefix wildcards. Unknown verbs never throw; repeat calls **intersect**, so a
  preset cannot be widened downstream.
- **`Diagnostics\Checks\FeedCoverage`** — every verb must be *decided*: named in
  the allowlist or denylist of at least one **restricted** preset. An open preset
  classifies nothing. The verb universe is `registeredVerbs()` ∪ verbs actually
  recorded, because the leak case is the verb nobody declared. Warning severity,
  so `--fail-on=warning` is the CI guardrail.

Additive throughout: the payload contract does not move, and an app that never
calls `feeds()` emits byte-identical SQL and sees no new findings. Group counts
and the distinct-role counts behind ":actors and 3 others" recompute **inside**
the filter. It filters events, not fields — recording discipline stays
load-bearing. See `docs/feeds.md`.

## v0.7 — authoring DX (released as part of v0.8.0-alpha.1)

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
