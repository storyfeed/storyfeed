# Roadmap

Storyfeed is being built in the open, on the road to a stable 1.0. This is the
high-level plan; detailed design documents will be published as the milestones land.

**Versions are milestones, not dates**, and the milestones are ordered by what
makes the package genuinely usable rather than by what makes a version number
land. Nothing on this list is scheduled against a conference date: the talk this
work leads to is a demonstration of a working package, not a launch event, so
1.0 arrives when the contract has earned it.

## Shipped

- [x] **v0.1 — Foundation.** Schema (activities, entity snapshots, groupings), the
      fluent recording API (`Storyfeed::activity(...)->actor($user)->publish()`),
      snapshot-backed entities, workbench + test suite.
- [x] **v0.2 — Read model.** Feed query builder, scoped feeds, cursor pagination,
      the self-describing JSON payload (headline templates, icons, linked entities —
      renderers need zero domain knowledge).
- [x] **v0.3 — Payload contract.** Headline grammar + icon registries, feed events,
      retention (`storyfeed:prune`) and health checks (`storyfeed:doctor`); the feed
      payload shape documented as a versioned freeze-candidate contract, including
      grouped-activity nodes ("Bob, Sally, and 3 others uploaded files to Project X").
- [x] **v0.4 — Typed recording API.** An autocomplete-friendly write API (no magic
      methods), verbs declarable as enums, and the Activity Streams 2.0 vocabulary
      shipped as PHP enums verified against the W3C spec.
- [x] **v0.5 — DX & tooling.** Test fakes, grammar-coverage assertions, named
      participants (`Party`), the documentation corpus — and **the Newsroom**: a
      deployed, living showcase app with simulated activity you can watch and poke.
- [x] **v0.6 — Read modes & Activity Streams 2.0** *(tagged `v0.6.0-alpha.1`)*.
      Three explicit read modes (`->log()`, `->live()`, `->summary()`), multi-axis
      grouping, `involving()` as a first-class indexed read, self-healing snapshots
      and `sync_token` — plus spec-conformant JSON-LD serialization (`Activity`,
      `OrderedCollection`) behind opt-in content-negotiated routes. Alpha caveat:
      emitted documents reference an extension context that is not published yet,
      which blocks a beta, not an alpha.
- [x] **v0.7 — Authoring DX** *(released as part of `v0.8.0-alpha.1`)*. `Story`
      classes — one class per activity type instead of seven registration sites —
      with `make:story`, `PublishesToFeed` for domain events, a structured `doctor`
      report you can assert on in CI, `storyfeed:stories`, `storyfeed:cache`, and
      strict grammar in local/testing.
- [x] **v0.8 — Audience scoping** *(tagged `v0.8.0`)*. Named feed presets
      registered once (`Storyfeed::feeds([...])`, entered as
      `Storyfeed::feed('customer')`) and `Feed` classes that take their subject as a
      typed constructor argument, so an unscoped feed cannot be built. `->only()` /
      `->except()` verb allowlists, a `FeedCoverage` doctor check so a verb that
      belongs to no audience fails CI rather than leaking, and `storyfeed:demo` for
      showing a feed without production data. In the MIT core, deliberately: nothing
      that makes a feed safe to show belongs in a paid package.
- [x] **v0.9 — What renderers need** *(tagged `v0.9.0`)*. The first milestone driven
      by consumers rendering the payload rather than by the package's own plan: group
      nodes carry their pinned roles as singulars instead of leaving every renderer to
      reconstruct them, an activity's `data` accepts a typed DTO, and the trickle
      reports activities it cannot resolve instead of deleting them.
- [x] **v0.10 — Diagnostics and read-time media** *(tagged `v0.10.0`)*. New doctor
      checks on a redrawn severity axis, so `--fail-on=error` fires on a feed that
      renders wrong today; the `feedMedia()` read-time resolver contract on
      `Feedable`; and `FeedLink` back as a label with an optional link.
- [x] **v0.11 — Pre-launch preview** *(tagged `v0.11.0`, unveiled at GPUG on
      Sep 30, 2026)*. Body types, headlines and a live feed that reads curated
      groups; `storyfeed/ui` v0.2.0 renders it with Tailwind.
- [x] **v0.12 — Pictures and quotes are bodies** *(tagged `v0.12.0`)*. An `Image`
      body names the picture a row shows; quoted words are an `Excerpt`; the
      discussion-specific thread leaves core.
- [x] **v0.13 — Live bursts and renderer kits.** Live groups one action into
      bursts; Summary is retired. Free React, Vue/Inertia and Blade kits render
      the feed. Live first pages stay under 300ms at 3M activities in benchmarks.
- [x] **v0.14 — Distant relations** *(tagged `v0.14.0`)*. A model names its
      parent, and `involving()` reaches activity anywhere beneath it.
- [x] **v0.15 — Story classes and sharper filters** *(tagged `v0.15.0`)*. A verb
      can bind one Story class method; feeds filter by role type or exclude
      distant relations; never-recorded ancestry can be filled in without
      rewriting history.

## The countdown to 1.0

Each version below is a GitHub milestone holding its concrete issues. Issue
titles start with the version they target, such as `[0.17]`. Milestones show
the current plan: items may be reordered, moved to a later version or dropped as
the work teaches us, and a dropped item is closed as not planned with its reason.

- [ ] **[v0.16 — Cleanup and truth](https://github.com/storyfeed/storyfeed/milestone/1).**
      Remove what was promised to be removed before 1.0, and make every document
      match the code.
- [ ] **[v0.17 — Feed sources](https://github.com/storyfeed/storyfeed/milestone/2).**
      The database becomes one source among several, driver-style, so a feed can
      come from static content or an API and still render through the same
      payload. The roadmap on storyfeed.dev becomes its first feed.
- [ ] **[v0.18 — Read path at scale](https://github.com/storyfeed/storyfeed/milestone/3).**
      Scoped feeds stay fast at a million activities on every supported database,
      with nightly benchmark gates.
- [ ] **[v0.19 — Payload and schema freeze](https://github.com/storyfeed/storyfeed/milestone/4).**
      The open points in the payload contract and the schema are settled, and
      Payload v1 is declared stable.
- [ ] **[v1.0 — Stable](https://github.com/storyfeed/storyfeed/milestone/5).** One
      consolidated schema, a versioning and support policy, and a release
      candidate before 1.0.0.

Curation, which activities group together and when, keeps improving behind the
stable payload contract and stays labelled experimental.

## After 1.0

Planned for the [1.x line](https://github.com/storyfeed/storyfeed/milestone/6), in
no particular order: a notifications bridge, Story auto-discovery, a public demo
API, more feed sources (Markdown, Laravel Pennant), sections other than dates,
and multi-tenancy exploration. Long-range: ActivityPub.

## Under discovery

Ideas waiting on evidence from real applications before they join a milestone:

- [Audience-aware phrasing](https://github.com/storyfeed/storyfeed/issues/44),
  such as "You closed the list" for the reader who did it.

## Sponsor-funded

These items wait for funding. Each one adds a long-term support promise or
needs research beyond the current plan. Everything else on this roadmap is
built regardless.

| Item | Why it waits for funding |
|---|---|
| **Exceptional scale** | Feeds that stay fast at billions of activities without pruning. This needs research into partitioned and tiered storage behind the same cursor, so the payload does not change. Performance at normal scale is part of the plan and is not waiting on funding. |
| **Agent reliability** | A modelling guide comes first, as part of the plan. Proving that coding agents set up and record a feed correctly without a person correcting them needs continued research: testing agents against real apps, finding where they go wrong, and building the docs and tools that close each gap. |
| **Official UI adapters for frontend frameworks** | Livewire, for example. React, Vue/Inertia and Blade kits in `storyfeed/ui` are available now. Each further framework is another set of components to maintain. |
| **Support for additional drivers such as SQL Server** | Storyfeed is tested on SQLite, MySQL 8.0+, MariaDB and PostgreSQL on every commit. A test run on SQL Server passes all but a handful of cases, but adding a database to the supported list is a promise to keep it working. |

[Sponsor Storyfeed on GitHub](https://github.com/sponsors/storyfeed)

## Design principles

1. **Curated, not logged.** A feed is deliberate storytelling about your domain —
   not an audit trail of every model save.
2. **Headless and self-describing.** The core emits a versioned payload that fully
   describes every item; any renderer can consume it.
3. **Activity Streams 2.0 under the hood.** Laravel-native storage and DX, W3C
   semantics at the serialization boundary.
