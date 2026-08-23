# Changelog

## v0.8.0-alpha.2 — Feed classes, and a scope that cannot leak (unreleased)

The alpha.1 lane shipped the allowlist half of audience scoping. This one ships
the SCOPE half — the half that fails open — plus the two places a scope could
already be escaped entirely: a `query()` callback's `orWhere`, and an AS2.0
route that never had a scope to begin with.

### Removed

- **The AS2.0 collection route, `GET {prefix}/feed`.** It ran
  `Activity::query()->published()->cursorPaginate()` — **every published activity
  in the system**, unscoped, with no verb allowlist and no named feed behind it.
  An app that switched the AS2.0 routes on exposed its whole activity table over
  HTTP, in a package whose entire v0.8 milestone is about deciding who may see
  which verbs. The route predated named feeds and was never reached by them: it
  built its own query, so neither the allowlist nor the `query()` nesting change
  above ever applied to it.

  It was removed rather than scoped or documented. There is no way to make it
  safe without deciding **which named feed backs it**, and that is a design
  question rather than a patch — while it is open, a firehose nobody can switch
  on cannot leak. It returns when the question is answered.

  The single-activity route `GET {prefix}/activities/{uid}` **stays**: it is
  addressed by an unguessable ULID rather than enumerable, and `published()`
  gates it.

  **Serializing a collection is unaffected.** `Serialization\CollectionSerializer`
  still emits `OrderedCollection` / `OrderedCollectionPage` with `partOf`, the
  opaque `next` cursor and no `totalItems` — the shape is AS2.0 roadmap work and
  none of it was the problem. Its `feed(?string $cursor, int $limit)` method,
  which built the unscoped query, is replaced by
  `collection(CursorPaginator $page, string $iri, ?string $cursor = null)`: the
  activities and the IRI both come from the caller now, so the class has no way
  to reach past what it was handed. Coverage of the collection shape moved from
  the HTTP tests to `CollectionSerializerTest`, against the serializer directly.

### Added

- **`Storyfeed\Feed`** — one class per audience, the declarative form of a named
  feed. The subject is a typed **constructor** parameter and `CustomerFeed::make($order)`
  is the only way in, so PHP itself refuses to build an unscoped feed; nothing in
  the package has to enforce it. `define()` declares what the feed is about
  (verbs, mode, limit) and must not touch constructor state, because
  `storyfeed:doctor` reads it without being able to supply a subject; `scope()`
  binds what only a request can supply. Closures remain first-class and both
  forms compile to one registry. See `docs/feeds.md`.

  A closure preset can carry the allowlist; it cannot carry the scope, because it
  runs at boot before any subject exists. The two halves fail in opposite
  directions and only one fails safe: forget the allowlist and a customer sees
  too little, forget `->involving($order)` and a customer sees **every order in
  the system**, correctly verb-filtered and entirely plausible. That asymmetry is
  the whole reason this class exists.

- **Scope is locked once bound.** Re-binding a role on a scoped Feed —
  `->context($someoneElse)` — throws rather than silently swapping the scope the
  surface was built on. Narrowing stays open: another `only()`, a `query()`, a
  different mode. `only(A)->only(B)` was already `A ∩ B`, so the allowlist half
  came free. **Only Feed classes lock anything** — plain builders, closure
  presets and `$model->storyfeed()` are untouched.

- **`make:feed`** — generates a Feed with its typed constructor already written,
  which is the step people skip and the step the guarantee rests on.
  `--from-doctor` writes **one** class with the undecided verbs commented out:
  one feed per verb would have been a restricted feed naming its verb, which
  `FeedCoverage` counts as decided, so the generator would have turned the check
  green while nobody decided anything. The generated file deliberately cannot
  make the check pass.

- **`Exceptions\FeedMisconfigured`** — the four ways a Feed class can be wrong,
  each naming the fix: a subject feed reached by name, a Feed that takes a
  subject and never binds it, a re-bound role on a locked scope, and a
  registered class that is not a Feed.

- **A PHPStan rule for `Feed::make()`.** `CustomerFeed::make()` with no arguments
  was only ever an unconditional runtime `ArgumentCountError`: `make()` forwards
  variadically to a reflected constructor — it has to, because the constructor
  varies by subclass and that variance is the feature — so an analyser saw a
  variadic and said nothing. `Storyfeed\PHPStan\FeedMakeArityRule` resolves the
  static call against the constructor it will actually reach and checks arity
  where the call is written. Apps get it automatically through
  `phpstan/extension-installer`; no configuration. Arity only — argument types
  are already PHPStan's business. Nothing about the runtime changed.

- **`Testing\FeedAudience`** — "this feed cannot render this verb", as an
  assertion in the app's own suite: `assertRefuses('customer', 'order.margin_note')`,
  `assertAllows()`, `assertAllowsOnly()`. An app adopts named feeds because it
  fears leaking an internal verb to a customer-facing surface, and until now the
  only thing standing between that fear and production was a registration in a
  service provider nobody re-reads.

  It is a different question from the `feeds` doctor check, and both are worth
  asking. `FeedCoverage` asks whether every verb is **decided** — named by
  somebody's allowlist or denylist — which is vocabulary hygiene, answered across
  the whole registry, and can be entirely green while the customer feed renders
  `order.margin_note`, because being denied in the *admin* feed counts as decided.
  This asks whether **this** feed shows **this** verb.

  It reads the feed's **declaration** rather than its rows, through the same
  `inspect()` seam doctor uses — so one class works identically under
  `Storyfeed::fake()` and against real tables, and can inspect a subject feed
  whose constructor a test cannot satisfy. The empirical form (publish the verb,
  read the feed, assert it is absent) was rejected: it passes vacuously whenever
  the fixture happens to lack the verb, which is the normal case for precisely
  the verb you are afraid of.

  Underneath it, `VerbFilter::admits()` — the PHP twin of `applyTo()`'s SQL, with
  a parity test over eight presets and seven verbs so the two cannot drift.

### Changed

- **`query()` callbacks are now always nested — a top-level `orWhere` can no
  longer widen a feed.** This is a behaviour change, and it changes emitted SQL.

  Callbacks registered with `FeedBuilder::query()` were applied at the top level
  of the candidate query. Because AND binds tighter than OR, a callback whose
  first move was `orWhere` became a *sibling* of everything the builder had
  already applied, and the read compiled to:

  ```sql
  where (published and involving and theirs) or (their other thing)
  ```

  An app could therefore surface **unpublished (including scheduled-for-later)
  activities**, or activities **outside the scope it asked for** — another
  tenant's, another model's — from a feed that read as correctly scoped in PHP.
  Nothing in the page announced it: the payload was well formed, the counts were
  internally consistent, and the extra rows looked like ordinary feed entries.

  This was a bug rather than a feature. `query()` is documented as the way to
  *narrow* the candidate activities; there has never been an intended way to use
  it to widen past `published()` or past the role scope, and no page shape
  depended on the escape. It predates the v0.8 feeds work.

  v0.8.0-alpha.1 already nested callbacks, but **only when a verb allowlist was
  active** — so whether your callback was safe depended on whether some other
  part of the feed happened to call `only()`/`except()`. The nesting is now
  unconditional.

  **What changes in the SQL.** A feed with no `query()` callback is byte-for-byte
  unchanged. A feed whose callbacks only ever AND gains one pair of parentheses
  around them and is unchanged in meaning — `and not "verb" = ?` becomes
  `and (not "verb" = ?)`. A feed whose callback used a top-level `orWhere` changes
  in meaning, which is the point: the OR is now confined to the group the
  callback built, and that group AND-s against the publish gate, the role scope
  and any verb allowlist. `query()` can narrow a feed and can never widen it.

  If you were relying on the old shape to deliberately reach past a scope, the
  supported way is a second feed read, or a `query()` callback that names the
  wider set inside its own closure. Nothing else in the read path moved.

- **`storyfeed:doctor` now reads a single `verb()` as a restriction rather than
  an open feed.** `FeedCoverage` inspected `verbFilter()`, which only
  `only()`/`except()` populate — so a preset written as `->verb('confirm')`
  looked wide open: it classified nothing, and a typo in it hid the real verb
  exactly as a typo'd allowlist entry does, with neither reported.

  Closed through the existing seam rather than by reading the same state a second
  way: `FeedBuilder::declaredVerbFilter()` folds the single verb in as an allow
  rule — identical semantics, the same equality in the same SQL — so `mentions()`,
  `literals()` and `admits()` all keep working and no consumer has to remember to
  ask about `verb()` separately. `admits()`/`isVerbRestricted()` and
  `Testing\FeedAudience` go through it too.

- **A `--only=` name that matches no check is now a warning, not silence.**
  `--only=grammer` ran nothing and reported nothing, which reads as a clean bill
  of health: an app gating CI on `--only=` got a green build from a check that
  never executed — the vacuous pass the testing helpers refuse by design, sitting
  in the diagnostic layer itself.

  A finding rather than a throw, deliberately. The registries guarded below are
  called once at boot with literals; `doctor()` takes runtime input that may
  already be whatever an operator typed after `--only=`, and killing a scheduled
  health check would be a worse bug than the one being closed. It is also the
  rule Doctor already lives by one level down — a check that throws becomes a
  finding rather than taking the run with it. **Warning** severity, because that
  is the entire fix: an Info would leave the build green and the vacuous pass
  alive, with a line in the report that merely *looks* like the system noticed.
  The message lists the valid names. Tested through the exit code under
  `--fail-on=warning`, which is what proves the bug closed rather than annotated.

- **Doctor names the ungrouped rows the trickle can no longer reach.** The silent
  half of a two-command trap, both commands individually correct. An app that
  bulk-inserts history has no grouping rows; `storyfeed:trickle` is what converges
  them, but it only looks at `uncached()` activities and `storyfeed:rebuild`
  caches every one of them. Run rebuild first and the import is ungrouped forever
  — a wall of solo nodes — while doctor reports a **clean** backlog, because the
  backlog counts uncached entities rather than missing groups. A trap that is
  silent in the exact tool an adopter runs to check their migration is the worst
  shape a trap can have. The finding names the way out (`storyfeed:curate --rehash`)
  and the sequence that caused it.

  It asks the strategy rather than counting absence. Under the shipped axes every
  activity groups — `repeat` requires no roles, so even a bare verb emits a hash
  — but `NullStrategy` ships and `grouping.strategy` is swappable, so an app that
  turned grouping off has a whole table of legitimately ungrouped rows. Screaming
  at those would get the check disabled and take the real signal with it. Absence
  is the alarm's size, a bounded re-run of today's strategy is its evidence, and
  the message quotes both numbers rather than extrapolating one from the other.

### Fixed

- **`FeedBuilder::for()`'s tombstone taught an API that never shipped.** Its
  message pointed at `CustomerFeed::involving($model)` / `::context($model)` —
  the role-named entry API drafted and then discarded in favour of `::make()`.
  Two docblocks carried the same discarded draft. A test now extracts every
  `Class::method()` and `->method()` reference from both tombstone messages and
  asserts each one resolves, so a tombstone is free to be rewritten and unable
  to lie. It matters where it stands: a tombstone is what a person reads when
  they are already confused.

- **Five registries accepted a list where they document a map, and each one
  failed silently in its own way.** `Storyfeed::verbs(['order.placed'])` — the
  list form instead of the documented map — registered the **integer `0`** as the
  verb and `order.placed` as its activity type, which `normalizeTerm()` then
  preserved verbatim, because extension types must round-trip. The app ends up
  with a vocabulary doctor believes in and `verbs.strict` rejects every real verb
  against. All five now throw, and each message names the map form and the enum
  form, because a message that cannot show the right call is not worth throwing:

  - `verbs()` — the case above.
  - `grammar()` — the same bug in its quietest form: key `0` matches no
    `(type, verb)` pair that will ever be asked for. Every headline stays null,
    nothing throws, and doctor reports the grammar as **missing** — pointing at
    the templates the developer is looking straight at.
  - `aggregateGrammar()` — the same, for the collapsed forms.
  - `icons()` — the same.
  - `objectTypes()` — key `0` is not a morph alias, so a list registers a mapping
    nothing ever asks for: activities serialize with no AS2.0 object type and the
    JSON-LD reads as merely under-specified rather than misconfigured.

  Found by writing tests for `FeedAudience` and hitting the first one, then by an
  audit for the shape; the fifth was found only by that audit. It is the argument
  for fixing rather than documenting: nobody can be relying on this, and it bites
  on day one. The `@param` key type on `verbs()` was corrected to `array-key`
  alongside — PHPStan was right that the guard contradicted the annotation, and
  the cost of that lie was the bug.

- **`Storyfeed::verbs(SomeEnum::class)` throws when the enum is not a `FeedVerb`.**
  The class-string form returned `[]` for an enum that forgot
  `implements FeedVerb` / `use AsFeedVerb`, and for a class-string that does not
  exist at all — registering **no** vocabulary, silently. Doctor then reported
  `verbs.undeclared`, which reads as "you have not declared a vocabulary yet" to
  someone who just did, on the line above. Both cases now name what is wrong and
  both accepted forms.

### Also

- `storyfeed:doctor` findings that name a feed now carry `file:line`. Closures
  reflect too, so both forms get it; the class form's advantage is a stable
  identity that survives someone reordering the provider array.
- `README.md` states the pre-1.0 reality plainly — not announced, breaking
  changes without a deprecation cycle, pin a commit rather than a range — and
  carves out the two things that do not move: the payload contract and the MIT
  commitment.
- The README's code is pinned by a test: a rename now fails a build instead of
  reaching a stranger. It is the most-copied artifact in the project and the one
  nobody re-reads after renaming a method — the same reasoning as the `for()`
  tombstones. Behaviour, never string-matching: each test *executes* the calls in
  the shape the README teaches them, so a wording change is free and a rename is
  not. Verified non-vacuous — renaming `PendingActivity::by()` fails 7 of the 10.
- The suite runs under `--parallel`. `ContextDocumentTest` called a helper defined
  in `SerializationTest.php`, which works only when both files load into the same
  process, so `vendor/bin/pest --parallel` failed on it and neither file could be
  run alone. Pre-existing, and it cost a confusing minute every time someone
  iterated on the AS2 layer. `serialize_one()` moved to `Pest.php`.

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
