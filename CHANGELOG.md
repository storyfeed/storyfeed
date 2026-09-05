# Changelog

## Unreleased

Two doctor checks, both from the same discovery: a check can be entirely right
about the database and still wrong about what the reader will do next. A switch,
from the first upstream issue a consumer filed. A second switch, from a doc
that described a deletion the code had never done. And a new read-time resolver
contract, which exists because two consumers read out every `Feedable` they had
and one of them turned out to be returning `null` from all of them — folded into
`Feedable` itself before this release, so the interim never ships.

### Breaking

- **One contract.** `Feedable` is now `toFeed()` + `static feedMedia(FeedContext):
  ?FeedMedia`. `Contracts\HasFeedMedia`, `Feedable::toFeedLink(array)`, `FeedLink`
  and `FeedMedia::fromLink()` are **removed**, and `Support\LinkResolver` no longer
  falls back. **Every `Feedable` that still declares `toFeedLink()` stops satisfying
  the interface** — a fatal at class load, not a quiet degradation — until the
  method is renamed and its body reads `$context->data(...)` instead of `$data[...]`
  and returns a `FeedMedia`. Mechanical, not subtle; roughly thirteen classes
  across the two pilots plus the reference renderer. (#6)

  The interim was announced as additive until v1. It is folded now because two
  contracts were a DX cost every consumer paid for months to buy a migration that
  had to happen anyway, and because folding early is *cheaper in total
  migrations*: the second pilot had not moved at all and was waiting on an
  upgrade guide, so it migrates once, against the final shape, instead of once to
  the interim and again at v1. Everything still open — `$context->model()` (#4),
  the viewer question, media expansion — arrives as an accessor on `FeedContext`
  or a slot on `FeedMedia`; neither touches the interface again.

  **`Concerns\InteractsWithFeed` now supplies `feedMedia()` returning `null`**, so
  `implements Feedable` + `use InteractsWithFeed` compiles on first save with only
  `toFeed()` left to write, and a model that has nowhere to point declares nothing.
  It deliberately does *not* default `toFeed()`: a missing link is a state; a
  missing label is a defect, and a guessed label would write a degraded snapshot
  the reader could not tell from a bug. `Models\Party` answers `null` itself
  because it does not use the trait.

  Three things the contract now says in its docblock that it did not before, all
  raised by a consumer running it in production: a resolver **may be called for
  entities that are never rendered as links** (group exemplars go through the
  same presenter), so it must stay cheap and side-effect-free; **`default =>
  null` is not optional** for a resolver that matches on `$context->feed()`, because
  an unhandled match on an ad-hoc builder or in the AS2 serializer degrades to no
  link silently; and **a resolver's URL is per-feed authority** — a kitchen feed's
  signed link is correct for that feed and must never be served on another, so a
  payload is cached per feed or not at all. `docs/payload.md` carries that last
  one in full.

- **`Support\Noun` is now `Storyfeed\FeedNoun`, and `phrase()` is gone.** The class
  is consumer-facing — `FeedNoun::trans('nouns.delivery')` is the documented way to
  register a translated noun — so it follows the convention every other value object
  on the contract surface already follows: `FeedEntity`, `FeedContext`, `FeedMedia`,
  `FeedImage`. The prefix is provenance before collision: an unprefixed `Noun` in an
  application's `use` block reads like something the framework provides, and
  `Support\` reads like plumbing, which tells the importer the wrong thing about what
  they hold. Transcriptions under `ActivityStreams\` keep W3C's bare spelling on
  purpose; that is the other half of the same convention. **The migration is one
  line**: `use Storyfeed\Support\Noun;` becomes `use Storyfeed\FeedNoun;` and every
  `Noun::` becomes `FeedNoun::`. There is no alias — the break is taken now, while it
  costs one `use` line, rather than after the surface is documented further. (#7)

  **`phrase()` — "1,204 clauses" — is removed in the same breath.** The rung stopped
  printing its number (below), which left it with no caller in core, and nothing in
  the docs ever taught it: `of()` and `trans()` are the registration surface,
  `form()` the selection. Its one claim to stay was the thousands grouping, and that
  grouping was `number_format()` — right in English, wrong in Polish ("1 204") and
  German ("1.204"), an English guess shipped on the app's behalf, which is exactly
  what this class refuses to do for plurals. An app that wants the number said
  composes it with its own locale-aware formatter:
  `Number::format($n).' '.FeedNoun::form($noun, $n)`. One break, not two.

### Added

- **Superseding keeps history, and now says so — `storyfeed.replace.delete`.**
  `publishAndReplace()` retires the earlier rows for its `(object, verb)` with a
  plain `delete()` on a model that soft-deletes, so a superseded activity has
  always stayed in the table with `deleted_at` set. The published docs said
  hard-deleted. The docs lane found the disagreement while drafting the
  "latest wins" page, and asked the right question: which one was intended?

  The soft delete was, and it stays the default. A superseded row is history —
  "this was true and is not any more" — and an operations vault that
  supersedes a status tick fifty times a day still wants to answer "what did it
  say at 14:02?". The row leaves the feed and every query the package makes;
  its participant rows go with it, because `involving()` is an index over rows
  that exist; its grouping rows stay, inert, because curation and the read path
  only ever reach a grouping through the live activity it points at, and
  `storyfeed:prune` sweeps them with the activity. Nothing about that changes
  for an app that sets nothing.

  What is new is the choice. `'replace' => ['delete' => 'force']` hard-deletes
  the superseded rows inside the publish transaction, grouping rows and
  participant rows included — there is no DB-level cascade, by design, and a
  hard-deleted activity may leave nothing behind pointing at it. It is for the
  app where a busy repeatable verb would otherwise accumulate trashed rows for
  the life of the table and nothing ever reads them back. Same idiom as
  `recording.enabled` and `trickle.prune`: the keeping default is on
  everywhere, the destructive one is an explicit word in config, never an env
  flip. Any other value throws on the first `publishAndReplace()` rather than
  guessing — before anything is written, and whether or not there is yet
  anything to supersede, because a typo that only bit on the second publish
  would ship.

  Two questions the finding raised, answered while in there and needing no
  change: the trickle does **not** walk soft-deleted rows (it queries through
  the default scope, so a superseded row can neither be re-snapshotted nor
  counted as an orphan), and nothing on the read path reaches them with
  `withTrashed()` — the only callers are prune, the participants backfill, the
  demo seeder's reset, and `forceDeleteFromFeed()`, all of which mean it.

- **`unrestricted()` — a world feed says so, and doctor believes it at the right
  volume.** An operations portal carried eleven permanent `feeds.unclassified`
  warnings, unresolvable by design: its portal feed declares no allowlist on
  purpose, because a world feed that quietly dropped a verb would be lying about
  being one. Technically right, practically noise — the audience decision *was*
  made, and it was "everyone". A feed can now write that down:
  `fn ($feed) => $feed->unrestricted()->summary()`. It is not a filter and not a
  widening; it changes no query, and a call site can still narrow downstream.
  What it changes is severity: a verb named by no restricted feed is reported as
  **`feeds.unrestricted`, Info**, when a registered feed declares itself the
  world, instead of `feeds.unclassified`, Warning. It stops failing
  `--fail-on=warning`; it does not stop being reported.

  What was asked for first, and refused by the consumer themselves once they saw
  it: a declaration that made covered verbs *decided*. That reintroduces the hole
  the `feeds` check was written around — green on day one and green on the day
  someone records `order.margin_note` — because it would auto-decide every verb
  that does not exist yet, and the check's whole value is firing for the person
  who did not write it. So declaring changes the severity and never the silence.
  Omitting an allowlist stays a Warning, since forgetting is not the same act as
  declaring, and `->only([...])->unrestricted()` throws at boot rather than
  letting the read path honour the filter while doctor honours the word. Same
  shape as `aggregates.latent`: reported, no Fix, Info not Warning.

- **`hydration` — every Feedable whose resolver loads its model, named.**
  `$context->model()` is a real capability with a real bill, and the bill was
  invisible: nothing in a payload, a test or a page said that a feed loads
  models. It would have shown up as a slow surface months later, blamed on
  whatever shipped most recently. Doctor now names the classes that pay, as
  **Info** — hydrating is a legitimate choice, not a defect, and a report that
  warns about a decision the author made on purpose is the report people stop
  reading. On an app where no resolver asks, the check says nothing.

  **How it knows.** Three detection strategies were on the table (#5). Static
  analysis of the resolver body loses the first time a resolver delegates to a
  helper. A declared marker relies on the author remembering, and the whole
  point is the author forgetting. The runtime flag `ModelHydrator::requested()`
  is accurate, but a page's map dies with the page, so doctor would only ever
  see what happened to render before it ran. So doctor **runs the resolver
  itself**: each Feedable is handed a `FeedContext` whose identity map was built
  disabled and asked for its media once per registered feed plus once unnamed,
  the way an ad-hoc builder and the AS2 serializer ask. A disabled map records
  the request before it consults the switch, so the probe learns who asks with
  no query and nothing loaded. That is read-only by contract, not by hope —
  `feedMedia()` is documented as a pure function of its context and is already
  called for group exemplars that are never painted.

  The probe uses the newest snapshot recorded for the alias as its
  representative row. A class with no snapshot yet is probed with an empty one,
  and a resolver that throws on *that* is passed over in silence — it has never
  run, and a naive `$data['id']` throwing on nothing is not evidence. A resolver
  that throws on its **own** snapshot is `hydration.opaque`, Info: an unanswered
  question is not a clean answer. `hydration.model` names the class, the alias
  and the feeds it was seen hydrating under, and says so when
  `storyfeed.hydration.enabled` is off. `hydration.page` reads the most recent
  default page of activities and counts the hydrating classes on it — one query
  each — because a page's cost is per page, not per app, and "this page pays
  two" is a number an operator can hold against a slow-query log. What no probe
  can count is nested access past a hydrated model; the `model()` docblock
  already calls that the N+1 it is.

- **`entities` — a model that fills a feed role and cannot be resolved, named
  row by row.** `surface` checks a model that *implements* Feedable and never
  appears. Nothing checked the mirror: a model that *does* appear and implements
  nothing. The sharpest case is the host app's own `User` — the package fills the
  actor role from the authenticated user automatically, so it is the one model an
  integrator never wires up by hand and was never told about. An operations
  portal found every action its operator had taken rendering degraded in their
  own audit vault, and the trickle counting the rows as unresolved on every run.

  Four causes, four codes, because the fix differs each time:
  `entities.unresolvable` (the alias resolves to no class), `entities.not_model`
  (it resolves to something that is not Eloquent), `entities.unfeedable` (a model
  with no `Feedable`), and `entities.missing` (a Feedable whose row is gone, or
  hidden by a global scope — the same verdict the trickle reaches, so the rows
  named are exactly the rows it counts, and would delete with pruning on). Each
  names the role, the alias, the class, the activity count and example activities
  to go and look at — "8 entities, 1 missing" was accurate and unactionable, and
  the consumer had already built the named version for themselves. The row-level
  cause is sampled, never scanned: fifty uncached ids per role and alias, one
  `whereKey()` per class. A row that is present and uncached is `backlog`'s
  business and is left to it.

  `entities.auth_model` needs no table and no traffic: if the configured
  authentication model is not Feedable, the first doctor run says so, not the
  day after go-live. It is skipped when `storyfeed.actor_resolver` is configured,
  because then the auth model may never be the actor; a runtime
  `resolveActorUsing()` closure is not visible to it, and the recorded-alias pass
  catches whatever that closure chooses once it runs. This is the one finding a
  fresh install may meet before writing a line — deliberately, since it is the
  line it needs to write.

  Both checks are additive, never throw, never write, and degrade to silence on
  a database that is not there. Both are `--only=`-able by name, and both are
  the same idea as the read-mode checks before them: report what a feed actually
  does, so a surface's behaviour is a fact someone can read rather than infer.

- **The door the pilots did not need.** `FeedContext::model()` hands a resolver its
  live model — lazy, and batched per class across the page. Neither production
  consumer reads anything outside `$data` today, and it is added anyway, on sixteen
  years of having wanted it: a snapshot is per-row storage, so a fact copied into it
  to save a query goes stale and has to be trickled, and the model itself was only
  ever withheld on cost. The cost is now flat. The presenter already holds a snapshot
  for every entity on the page, so it seeds an identity map with the complete
  `(type, id)` set before any resolver runs; the first Customer to ask loads every
  Customer on the page in one `whereKey()`, every later one is a map hit, and a
  resolver that never asks pays nothing. Measured on a twenty-node page across three
  classes: one hydrating class is one extra query, two is two, `with: ['customer']`
  on top is three, and the same relation read without `with:` is thirteen — the
  nested-access footgun the docblock warns about, priced. (#4)

  An accessor, not an injected parameter: a signature that quietly hydrated would
  make a page slower with nothing at the call site to say so, and a signature cannot
  express `with:`. Every way it cannot resolve is `null` and none of them throws —
  row gone, soft-deleted (`withTrashed: true` opts in, on classes that soft-delete),
  unresolvable alias, a batch that threw (reported once), or hydration switched off
  with the new `storyfeed.hydration.enabled = false` for a surface that needs a
  no-queries guarantee. The AS2 serializer resolves one activity at a time and has
  no page to seed from, so there the call is a single lookup: correct, not amortised,
  and stated in its docblock so nobody benchmarks it as a regression. The map is per
  payload build — `NodePresenter::forPage()` is a copy, for the reason `forFeed()`
  is — so a singleton-bound presenter cannot serve one page's models to the next.

  One consequence, documented rather than discovered: the label comes from the
  snapshot and the link from the live row, so after a rename a node can read with
  the old name while linking to the new record. A resolver that has paid for the
  model can close that gap by returning a `label:` from it. `docs/payload.md`
  carries the whole statement.

- **The array that could not grow.** `Feedable::toFeedLink(array $data)` had
  stopped being about links — it returns a url, a label override, link
  attributes and a modal hint — and it could not learn anything new, because in
  PHP a parameter added to an interface method breaks every implementation. Two
  applications running this package in production inventoried all thirteen of
  their `Feedable` implementations to settle what a replacement had to carry.
  Not one of them read anything outside `$data`; one returned `null` from every
  model because the same snapshot renders on three surfaces and the right URL
  depends on who is reading.

  `feedMedia(FeedContext $context): ?FeedMedia` is the successor. It landed as
  an opt-in `Contracts\HasFeedMedia` with `toFeedLink()` kept as the fallback,
  and was folded into `Feedable` before release — see **Breaking** above.
  `Support\LinkResolver` is the one seam the payload presenter and the AS2
  serializer both route through, so they cannot drift and neither knows more
  than "a `FeedMedia` or null". (#2, #6)

  `FeedContext` is a value object rather than more parameters, so the next thing
  it carries costs an accessor instead of a break.

- **The surface that declares itself.** `FeedContext::feed()` reports the
  registered name of the feed being read, so a resolver can return a different
  URL per surface — a signed operational link here, a public one there — from
  one snapshot. It is **declared, never sniffed**: not the request, not the
  route, not a panel, not who is logged in. Feeds render with no request at all
  (queued digests, console, the AS2 serializer, tests), a Livewire poll arrives
  through a shared endpoint that says nothing about the page, and a payload that
  varied by request would have no stable cache key. (#3)

  An ad-hoc builder reports `null`, and so does the AS2 serializer — a
  federation document has no surface and must not vary by one. A resolver's
  `default =>` arm is for both.

- **One feed, one name, whatever door.** A registered key now wins over the
  class-derived name everywhere: `'kitchen' => CustomerFeed::class` makes that
  class `'kitchen'` whether the read entered by key, by `CustomerFeed::make()`,
  or by class-string. Before this the constructor door reported `'customer'` and
  a resolver's `match` could be right on one page and silently wrong on the
  next. Compare against `CustomerFeed::name()` and the arm survives both a class
  rename and a key rename.

  A class registered under no key keeps its derived name, which is canonical
  because it is the only one. **`Feed::name()` now consults the container**, so
  a bare unit test asserting the derived name for a registered class will see
  the key instead; without a container bound it degrades to the derived name
  rather than throwing.

- **AS2.0 property vocabulary.** `ActivityStreams\Property` transcribes the nine
  properties the serializer actually emits, and `ActivityStreams\Extension\ExtensionTerm`
  defines the `sf:` namespace — one term, the app-level verb, because
  `ns.storyfeed.dev` is add-only and naming a term commits it before the code
  that emits it exists. `orderedItems` is the JSON-LD list alias for `as:items`
  rather than a term of its own, so its `iri()` says so while its value keeps
  the emitted spelling. Adoption in the serializer is a separate pass.


- **Recording is a switch, and the switch has knobs.** A consumer's 3,270-test
  parallel suite on Postgres deadlocked intermittently — eight `40P01`s in one
  run — because every test that exercised anything publishing a story wrote to
  seven tables, and autovacuum on `feed_snapshots` collided with the next
  worker's `migrate:fresh`. The tests that asserted on the feed were 43 of ~160
  files. Everything else was paying for rows it never read. (#1)

  The ask was a default that flips under `testing`. **Declined, on purpose.** A
  feed that silently records nothing in one environment breaks every feature
  test that renders a feed page, and env-flipped defaults are the "green in
  tests, empty in production" class of bug — the existing `verbs.strict: null`
  is a warning about that shape, not a model for it. What shipped instead is
  what Telescope, Pulse, spatie/laravel-activitylog and laravel-auditing all
  ship: a config key that is **on everywhere by default**, and runtime toggles
  that override it for one process.

  `storyfeed.recording.enabled` (`STORYFEED_RECORDING_ENABLED`, default
  `true`). Off, every `publish()` — the builder, `Storyfeed::record()`, a Story,
  a `PublishesToFeed` event, `->publish()` on a verb enum, a composite —
  composes its `Activity` and returns it **unsaved**: `uid` and `published_at`
  stamped, `exists` false, `id` null, so every call site stays
  source-compatible and Eloquent's own `exists` is the tell. No id is invented
  because an invented key can be handed to a foreign key. No party row is
  inserted for a string actor (parties resolve at association time, before
  `publish()` can decline — a muted suite that still inserts `feed_parties` is
  not muted), `ActivityPublished` is not dispatched, and Feedable models stop
  refreshing snapshots on save, which was the churn. Nothing throws. The
  development-time assertions still run: muted is not blind, and a quiet suite
  still catches a typo'd verb. Reads are untouched.

  `Storyfeed::stopRecording()` / `startRecording()` / `isRecording()`, and the
  scoped `withoutRecording(fn)` / `recording(fn)`, which restore the
  **previous** state — not the opposite one — including when the callback
  throws. Runtime state lives on the manager singleton, so it dies with the
  container between tests. **`Storyfeed::fake()` outranks the switch**: an
  explicit fake still captures with recording off, because a fake that honoured
  a global mute would fail `assertPublished()` for a reason nothing in the test
  file explains.

  Two traits in `Storyfeed\Testing`, picked up through Laravel's own
  `setUp{Trait}` convention: `RecordsStories` opts a class or a Pest directory
  back in (`uses(RecordsStories::class)->in('Feature/Feed')`) in a suite muted
  through `phpunit.xml`; `WithoutRecording` is the other direction. The whole
  recipe is two lines and no test is edited.

  A `recording` doctor check, because a switch left off in production is the
  quietest failure this package has — every feature keeps working and the feed
  simply stops. Warning outside `testing`; Info under it, so a suite whose feed
  assertions pass against zero rows is at least said out loud.

  Independently of all of the above, the testing docs now carry the Postgres
  note: `feed_snapshots` is high-churn by design, and a parallel suite should
  set `autovacuum_enabled = false` on the feed tables in test databases.

- **`roles` — a template may not name a role its activities never carry.** An
  operations portal was rendering a literal italicised "somewhere" inside its
  sentences. That is the `absent` placeholder doing exactly what it was built to
  do: a template named `:target`, the activities carry none, and the renderer
  politely printed the word rather than breaking.

  Which is the problem. A reader cannot tell "the target is unknown" from "this
  sentence should never have mentioned a target" — the authoring bug renders as
  **content**. Three checks already touched grammar without catching it:
  `Coverage` asserts a template exists, `AggregateTokens` validates aggregate
  tokens against axis pinning, `AggregateCoverage` covers clustered pairs.
  Nothing compared a **singular** template's tokens against the roles its
  activities actually have.

  Data-driven, like `Coverage` and for the same reason: reasoning about which
  roles an app's activities carry has been wrong in practice. **Two codes**,
  because the roles are not equivalent. `roles.always_anonymous` is Info for
  `:actor` — a null actor has a documented meaning, never conflated with a system
  actor, so the sentence still reads and this is reportage.
  `roles.never_carried` is a Warning for `:object`, `:target` and `:context`:
  their placeholders exist only so a template naming a missing role "still
  reads", which is precisely the behaviour that hid this for as long as it hid.

  Accumulated per **resolved template** rather than per pair, so a `*.*`
  catch-all naming `:target` is judged against everything it actually renders
  instead of being condemned by the first pair that lacks one. Only the **never**
  case is reported — a role carried by some activities is the placeholder earning
  its keep, and warning there would bury the finding. Closure templates are
  skipped rather than guessed at, and no Fix is offered: the remedy is authorial,
  and a stub here would be the wrong instrument.

  It is a debt rather than a discovery. Those rows sat inside a group this
  package started closing by default; they were visible on the dashboard
  yesterday and are behind a click now. Closing groups removed an app's ability
  to see its own broken sentences — the surface was doing this check informally,
  the package took it away, so doctor owes it deliberately.

- **The slot is the meaning.** The first image any consumer put in a snapshot
  was a dish photo, and the story was meant to LEAD with it rather than describe
  it. `FeedMedia` now carries four image slots — `icon`, `image`, `preview`, and
  `url` itself, which accepts a `FeedImage` as well as a string — and the slots
  are Activity Streams 2.0's own property names with AS2's own definitions. A
  photo object is `url` (the full image) plus `preview` (the derivative you paint
  in a list). Two URLs, on purpose: a single anonymous "media" field would have
  made every dense feed fetch the full image, and every renderer guess from the
  role what the picture was for.

  `FeedImage` is `src`, `mediaType`, `width`, `height`, `alt`. The snapshot
  carries the intrinsic facts and the resolver mints the `src` live, so nothing
  already recorded needs re-recording. Named arguments and fluent setters both
  work — `FeedMedia::make(url: $full, preview: $thumb)` or
  `FeedMedia::make($href)->preview($thumb)` — and the properties are
  `private(set)`, so the value is still immutable from outside.

  On the payload this is **`entity.media`, additive**: `null` when no slot is
  set (which is every resolver written before today), otherwise an object with
  all four keys. `entity.url` keeps its frozen string shape; when the resource
  is itself an image, `media.url` says so with its dimensions. On the AS2
  document each slot is a `Link` object carrying `mediaType`, `width` and
  `height` — the reason `Property` grew seven cases and `ns.storyfeed.dev` grew
  none. Degradation is unchanged: no snapshot, no resolver call, no media; a
  throw is reported and the entity arrives with nothing. What a grouped node
  does with its exemplars' previews is deliberately not designed yet.

### Changed

- **The noun rung no longer prints a number. Rendered output changes.** A group
  headline the rung generated used to read "Jasper Tey updated 2 terms sheets to
  current doctrine"; it now reads "Jasper Tey updated terms sheets to current
  doctrine". Same rows, same payload, different sentence — if a test or a
  screenshot of yours pins the old text, it changes.

  Why: in production that sentence rendered directly above a disclosure reading
  "Show all 5". The 2 counts sheets, the 5 counts acts, and nothing on screen says
  so. Two readers who knew the mechanism both read it as a bug, and a second
  reader reproduced the verdict independently on a 7-versus-9 case. The distinct
  count is the most truthful number available and the worst one to display. On
  the same screen a fully-pinned row with *no* number in the sentence, over
  "Show all 9", read perfectly. A sentence with no count over a counted
  disclosure reads clean; a sentence with a *different* count reads broken.

  The count survives only to select the plural form — "terms sheets" over "terms
  sheet", and in a three-form locale the right one of three — which is still a
  fact about how many there are. `count` and `distinct` in the payload are
  untouched; this is presentation, not contract. **Authored templates are
  untouched**: `:count` in your own aggregate grammar still prints and is still
  formatted by the renderer, at the end of the clause where an author puts it —
  the mid-sentence substitution the rung performs is what cannot carry a number.
  `FeedNoun::form()` is the number-free selection the rung now uses; `phrase()`,
  which used to produce "1,204 clauses", was kept at first for a caller that wanted
  the number said and then removed with the rename (see Breaking).

- **`aggregates` asks whether anything can actually read the pair.**
  `AggregateCoverage` warned for every clustered `(axis, verb)` pair with no
  aggregate grammar, regardless of whether any surface in the app would ever
  render that axis. A consumer got eight warnings on a `->live()` dashboard: five
  `object.*` and one `targets.*`, and live reads only `repeat` plus authored
  composites — so six of the eight templates it asked for by name could never
  have fired, and `--stubs` would have printed six unrenderable registrations.

  `Diagnostics\Reachability` reads each **registered** feed's declared mode, and
  a pair nothing can read is reported as `aggregates.latent` rather than as a gap
  to go fix. **Latent is not an all-clear and not silence**: the pair is still
  reported, under its own code, because a cluster forming with no template is
  worth knowing about and becomes a real gap the instant a surface changes mode.
  It carries no Fix, because printing an unrenderable stub is the exact harm
  being closed.

  It is only ever said when the registry can actually say it. With no feeds
  registered, or one feed that will not inspect, every pair reverts to the plain
  warning plus an `aggregates.reachability_unknown` note. **Caveats are emitted
  first**, so nothing below them can be read as a complete answer by someone who
  stopped at the first warning — silence that reads as coverage is the failure
  this check has already committed twice.

  Mode is a presentation default rather than a safety property, since any call
  site may override a feed's declared mode, so this only ever says "unreachable
  **as declared**", and says that out loud. `FeedBuilder::declaredMode()` joins
  `declaredVerbFilter()` as an `@internal` read-back seam; both exist because
  tooling has to see what a feed declared.

  **Known and not fixed**: the clustered query still selects winner rows only,
  while live mode reads every repeat row regardless of winner — so a
  live-readable repeat pair whose rows lost curation stays invisible. That is the
  mirror of the bug closed here, it is pre-existing, and it wants a failing test
  before a fix rather than more reasoning about a check that has now misled three
  times.

## v0.9.0 — Grouping says which day, and a group speaks for its members (2026-08-26)

Cut because a consumer needed this work in production and had no released
version to require. The alternative was pinning a live portal to dev-`main`,
which puts an unreviewed package change into a client-facing deploy the next
time anything unrelated ships.

Every item below was found by somebody **rendering** the payload rather than
reasoning about it. Between two renderers and an operations vault they turned up
a destructive default that arrived by following the install instructions, a
promise the payload made and left every renderer to keep for itself, and an
ordering that reversed itself on a mode switch.

### Changed

- **Pruning in the trickle is now opt-in, and off by default.** This is the one
  that matters. An activity whose role could not be resolved was **deleted** by
  the scheduled worker, by default — and the documentation recommends running
  that worker every fifteen minutes, so the destructive behaviour was what an
  installer got by following the instructions and reading no further.

  A consumer found the cost. In their portal **every** activity their operator
  had performed carried an unresolvable actor, because their `User` was not
  `Feedable` — so an entire class of "things the operator did" was queued for
  removal by a worker whose documented purpose is snapshot convergence. Their
  standing rule is no pruning at all: it is an operations vault and they need it
  for audit. Soft deletes make it recoverable, but the rows leave the feed
  silently and retention force-deletes them later.

  An unresolvable role is nearly always a missing `Feedable`, which is a bug in
  the app, and **deleting the evidence of a bug is a poor way to report it**. So
  the default counts them instead: `unresolved` in the action's return and in
  `storyfeed:trickle`'s output, which names the likely cause in two short lines
  rather than one wrapped paragraph — a console that truncates a paragraph drops
  the half that names the fix, and the half that names the fix is the reason for
  printing anything. `storyfeed.trickle.prune`, or `--prune`, turns deletion back
  on for an app that genuinely wants it. The flag is read as "on or defer", never
  as "off", so passing nothing cannot override an app that switched pruning on in
  config.

  **The starvation that made deletion look necessary is handled rather than
  inherited.** An orphan can never gain a cached id, so it matches `uncached()`
  forever, and a standing population of them would fill a limit-sized page every
  run and starve every newer row behind it — which is exactly why deleting them
  looked like the tidy answer. A run that steps over an orphan now keeps
  fetching, excluding what it has already examined, until it has done `limit`
  real snapshots or reached a bounded ceiling of five times `limit` rows
  examined. The ceiling is what stops a table that is entirely orphans from
  turning one run into a full scan. There is a test for precisely the case:
  three orphans, one good row, a budget of two, and the good row still gets
  snapshotted.

- **The solo tiebreak now descends, matching `log()`.** `soloStream()` ordered
  `published_at desc, id ASC` while `logPage()` has always ordered
  `published_at desc, id DESC`. Two activities published in the same second came
  back one way round in `log()` and the other way round in `live()` — nothing
  nondeterministic, an exact **reversal** on a mode switch, which is why a
  consumer's rename test flipped rather than flickered when they turned grouping
  on. On an audit surface "which happened first" is the question, and rows
  sharing a timestamp are routine on seeds and bulk imports. The cursor
  comparison flips with it: `id <` where it was `id >`.

  **Groups keep ascending**, and the first attempt at this flipped them too,
  which was wrong and the suite said so within a minute. A group's tiebreak is
  (axis, hash): arbitrary-but-stable naming, not recency. Reversing it reorders
  every tied page — the `actors` group and the `repeat` group swap places —
  while making no page more correct. `rank` sorts every group before every solo
  at a shared timestamp, so the two streams are never tiebroken against each
  other and each only has to agree with its own SQL and its own cursor. A
  tiebreak that carries no meaning should not be churned for symmetry with one
  that does.

  Reaching the branch at all meant deleting the grouping rows in the test: an
  ordinary recorded activity carries a winning grouping row, so in a grouped mode
  even a lone one is read as a group of one. The solo branch belongs to imported
  rows, rows predating the install, and rows awaiting the trickle — which is also
  exactly where same-instant ties cluster, because a bulk import stamps a whole
  batch with one timestamp.

### Added

- **A group node carries its pinned roles as singulars** (additive).
  `aggregateTokens('repeat')` lists `:actor` as safe — the axis key pins actor
  identity, so it is homogeneous by construction — and `safeSingularFallback()`
  admits a singular template on that basis. But `groupNode()` emitted roles
  **only** as exemplar lists, so a renderer meeting `:actor` had to know to reach
  into `exemplars.actors[0]`.

  Two renderers met it and handled it differently. The Vue one quietly
  reconstructs the singular; the Filament adapter rendered the **anonymous**
  branch — "The link sent to Someone was opened 5 times", sitting directly above
  five member rows naming the recipient correctly, on an audit vault. A promise
  the payload does not keep is the payload's bug, and "Someone" is a shrug with
  the authority of a fact.

  Group nodes now carry `actor`, `object`, `target` and `context` alongside the
  exemplar lists. The guard is deliberately belt-and-braces — pinned by the
  registry **and** one distinct entity in fact — because a custom axis declares
  its own pins, and a mis-declared one must degrade to the exemplar list rather
  than name one member on behalf of all of them. Additive: new keys on group
  nodes, nothing changed on activity nodes, so a renderer that ignores them
  behaves exactly as before. The frozen-shape contract test was updated
  deliberately, which is the only reason it exists.

- **`:verb` is a pinnable token.** It needs one field rather than an identity
  pair, and the verb is in four of the five inferred axis keys, so every member
  of such a group shares it by exactly the construction that makes `:actor` safe
  on `repeat`. Its absence from `Field::PINNABLE` was an omission, not a
  decision, and it under-claimed twice: an aggregate template naming the verb was
  refused for a group where naming it was simply true, and a renderer meeting an
  ungrammared group could not ask whether the verb was sayable — so it said
  "31 activities" where "31 clause.added activities" was available and honest.

- **`->data()` takes a typed DTO and stores a plain array.**
  `->data(LinkFetch::from($request))` with a spatie/laravel-data object, or
  anything else `Arrayable`. `FeedEntity` has accepted `Arrayable` for snapshot
  data since it was written; the activity's own payload had not, so the two
  halves of "describe this in a DTO" disagreed depending on which side of the
  seam you were on.

  The doctrine is the verb ladder's, applied to data: **the typed thing is an
  authoring convenience and storage stays plain.** An activity recorded from a
  DTO is byte-identical to one recorded from the array that DTO produces, which
  is what the first test asserts and the reason it is the test worth having — a
  DTO can be introduced or removed later with no migration, and no renderer can
  tell which was used. Nothing downstream learns a type: the payload still hands
  `data` over uninterpreted. Widened on both authoring surfaces, since a verb
  enum's `->data()` forwards to the builder's and a signature that disagreed with
  the thing it forwards to is a trap for whoever finds the shorter one first.

### Also

- **The grouping day is cut in a named zone, and it is written down because
  nothing on screen can say which one it was.** The `d` segment of an axis key is
  `published_at->toDateString()` — the application's zone, at publish time. A
  renderer's day headings are cut in its **display** zone, at read time. When
  those disagree the **group** wins: a burst straddling midnight in the reader's
  zone stays one group under one heading, because its members were bound together
  before any renderer had a zone to have an opinion in.

  A consumer proved it with seven opens of one link either side of midnight in
  Ontario — four on the 26th locally, three on the 27th, all seven the 27th in
  UTC — rendering as one group under "Today" with over half of it belonging to
  yesterday. The rows are ordered, the count is right, and the run reads as
  today's. There is nothing to notice, which is the entire reason the note
  exists.

  Not fixed, and deliberately: the grouping day is a publish-time value written
  into a hash, and it cannot know a read-time zone that may differ per reader.
  Making the two agree means taking the day out of the key, which is a different
  design with different costs and not one to adopt in passing. What an app can do
  is set `app.timezone` to the zone its feed is read in, so both cuts land in the
  same place — storage is unaffected either way, since only the derived date
  string reads the zone.

## v0.8.0 — Feed classes, and a scope that cannot leak (2026-08-23)

**The first stable tag.** The `v0.8.0-alpha.2` pre-release carried an earlier
draft of these notes; this section is that release plus the twenty-odd commits
that landed after it. The `v0.8.0-alpha.1` notes stay where they are, below.

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

- **`storyfeed:demo` — a seeded demo tenant, so a demo never needs production
  data.** The position it makes practical: **a feed is PII from its first row,
  and this package will never ship a redactor.** Snapshots are written at publish
  time, so redacting a feed for a screenshot means either rewriting history or
  filtering at presentation — and the second is the one thing the read path must
  not do. Worse, redaction fails open: it protects the field you remembered, and
  the field you forgot is the one on the projector.

  ```bash
  php artisan storyfeed:demo --days=30 --seed=4    # reproducible history
  php artisan storyfeed:demo --fresh               # clear the last one, seed again
  php artisan storyfeed:demo --clear               # remove it all, seed nothing
  ```

  It publishes through `Storyfeed::activity()` like application code, so party
  resolution, snapshotting, grouping and inline curation all really run — a demo
  that shortcut the write path would be showing an audience code nobody executes.

  **The whole cast is Parties**, not application models: no migrations, no
  factories, no domain coupling, so it seeds identically in any app. Entities are
  properly typed (`Person`, `Organization`, `Document`, …) so the AS2.0 surface is
  part of the demo rather than a page of `Service` nodes. What the trade costs is
  documented rather than hidden — every entity shares the `storyfeed.party` morph
  alias, so a seeded demo cannot show type-keyed grammar or a link resolver
  pointing at real records; pass your own models to `DemoSeeder` if you need them.

  **Deterministic**: the same `--seed` produces the same feed down to the minute,
  so a demo can be *rehearsed* and a screenshot in a doc still matches what a
  reader seeds tomorrow. Randomness is an LCG carried on the screenplay rather
  than `mt_rand()`, so seeding cannot disturb anything else in the process.

  **The days are shaped, not sampled.** A uniformly random feed reliably produces
  a wall of solo nodes, because grouping needs a burst of the same verb by the
  same actor on the same day to have anything to collapse. Each day composes a
  morning upload burst (repeat), a comment thread (actor collapse), an afternoon
  of task closing (target collapse) and scattered singles. One seed fills a world
  feed, a context feed and an actor feed.

  **Teardown is the half that has to be trustworthy.** Every seeded verb carries
  a `demo.` prefix and `--clear` matches on that and nothing else — no truncation,
  no JSON path expression, no "delete everything published before X". It cannot
  reach a row your application published, on any driver, and a test pins that by
  publishing a real activity, seeding, clearing and asserting the real row
  survives. The prefix is visible in dev tools and invisible in the rendered
  headline. Production is guarded by Laravel's own confirmation, so it behaves
  like `migrate:fresh`.

  **Seeding and rendering are two opt-ins**, and the second is easy to miss in
  the worst way: `config('storyfeed.demo.enabled')` registers the demo grammar at
  **boot**, and without it the feed you just seeded renders group nodes with
  empty headlines in every process that is not the seeder. The command warns when
  it seeds with the flag off. Off by default, because these verbs in an app's own
  registry are noise in doctor's feed coverage.

  See `docs/demo-data.md`, including what seeding does *not* solve: it makes the
  feed safe, not the rest of the application.

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
