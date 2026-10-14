# Roadmap

Storyfeed 1.0 means a stable Payload v1 that renderers can build on without
breaking changes, one audited schema, and a published versioning and support
policy.

**Versions are milestones, not dates.**

## Shipped

- [x] **[v0.1 — Foundation](https://github.com/storyfeed/storyfeed/milestone/7).** Schema (activities, entity snapshots, groupings), the
      fluent recording API (`Storyfeed::activity(...)->actor($user)->publish()`),
      snapshot-backed entities, workbench + test suite.
- [x] **[v0.2 — Read model](https://github.com/storyfeed/storyfeed/milestone/8).** Feed query builder, scoped feeds, cursor pagination,
      the self-describing JSON payload (headline templates, icons, linked entities —
      renderers need zero domain knowledge).
- [x] **[v0.3 — Payload contract](https://github.com/storyfeed/storyfeed/milestone/9).** Headline grammar + icon registries, feed events,
      retention (`storyfeed:prune`) and health checks (`storyfeed:doctor`); the feed
      payload shape documented as a versioned freeze-candidate contract, including
      grouped-activity nodes ("Bob, Sally, and 3 others uploaded files to Project X").
- [x] **[v0.4 — Typed recording API](https://github.com/storyfeed/storyfeed/milestone/10).** An autocomplete-friendly write API (no magic
      methods), verbs declarable as enums, and the Activity Streams 2.0 vocabulary
      shipped as PHP enums verified against the W3C spec.
- [x] **[v0.5 — DX & tooling](https://github.com/storyfeed/storyfeed/milestone/11).** Test fakes, grammar-coverage assertions, named
      participants (`Party`), the documentation corpus — and **the Newsroom**: a
      deployed, living showcase app with simulated activity you can watch and poke.
- [x] **[v0.6 — Read modes & Activity Streams 2.0](https://github.com/storyfeed/storyfeed/milestone/12)** *(tagged `v0.6.0-alpha.1`)*.
      Three explicit read modes (`->log()`, `->live()`, `->summary()`), multi-axis
      grouping, `involving()` as a first-class indexed read, self-healing snapshots
      and `sync_token` — plus spec-conformant JSON-LD serialization (`Activity`,
      `OrderedCollection`) behind opt-in content-negotiated routes. Emitted
      documents reference the extension context published at
      [`https://ns.storyfeed.dev`](https://ns.storyfeed.dev).
- [x] **[v0.7 — Authoring DX](https://github.com/storyfeed/storyfeed/milestone/13)** *(released as part of `v0.8.0-alpha.1`)*. `Story`
      classes — one class per activity type instead of seven registration sites —
      with `make:story`, `PublishesToFeed` for domain events, a structured `doctor`
      report you can assert on in CI, `storyfeed:stories`, `storyfeed:cache`, and
      strict grammar in local/testing.
- [x] **[v0.8 — Audience scoping](https://github.com/storyfeed/storyfeed/milestone/14)** *(tagged `v0.8.0`)*. Named feed presets
      registered once (`Storyfeed::feeds([...])`, entered as
      `Storyfeed::feed('customer')`) and `Feed` classes that take their subject as a
      typed constructor argument, so an unscoped feed cannot be built. `->only()` /
      `->except()` verb allowlists, a `FeedCoverage` doctor check so a verb that
      belongs to no audience fails CI rather than leaking, and `storyfeed:demo` for
      showing a feed without production data. In the MIT core, deliberately: nothing
      that makes a feed safe to show belongs in a paid package.
- [x] **[v0.9 — What renderers need](https://github.com/storyfeed/storyfeed/milestone/15)** *(tagged `v0.9.0`)*. The first milestone driven
      by consumers rendering the payload rather than by the package's own plan: group
      nodes carry their pinned roles as singulars instead of leaving every renderer to
      reconstruct them, an activity's `data` accepts a typed DTO, and the trickle
      reports activities it cannot resolve instead of deleting them.
- [x] **[v0.10 — Diagnostics and read-time media](https://github.com/storyfeed/storyfeed/milestone/16)** *(tagged `v0.10.0`)*. New doctor
      checks on a redrawn severity axis, so `--fail-on=error` fires on a feed that
      renders wrong today; the `feedMedia()` read-time resolver contract on
      `Feedable`; and `FeedLink` back as a label with an optional link.
- [x] **[v0.11 — Pre-launch preview](https://github.com/storyfeed/storyfeed/milestone/17)** *(tagged `v0.11.0`)*. Body types, headlines and a live feed that reads curated
      groups; `storyfeed/ui` v0.2.0 renders it with Tailwind.
- [x] **[v0.12 — Pictures and quotes are bodies](https://github.com/storyfeed/storyfeed/milestone/18)** *(tagged `v0.12.0`)*. An `Image`
      body names the picture a row shows; quoted words are an `Excerpt`; the
      discussion-specific thread leaves core.
- [x] **[v0.13 — Live bursts and renderer kits](https://github.com/storyfeed/storyfeed/milestone/19).** Live groups one action into
      bursts; Summary is retired. Free React, Vue/Inertia and Blade kits render
      the feed. Live first pages stay under 300ms at 3M activities in benchmarks.
- [x] **[v0.14 — Distant relations](https://github.com/storyfeed/storyfeed/milestone/20)** *(tagged `v0.14.0`)*. A model names its
      parent, and `involving()` reaches activity anywhere beneath it.
- [x] **[v0.15 — Story classes and sharper filters](https://github.com/storyfeed/storyfeed/milestone/21)** *(tagged `v0.15.0`)*. A verb
      can bind one Story class method; feeds filter by role type or exclude
      distant relations; never-recorded ancestry can be filled in without
      rewriting history.
- [x] **[v0.16 — Cleanup and truth](https://github.com/storyfeed/storyfeed/milestone/1)** *(tagged `v0.16.0`)*. Deprecated aliases
      are gone, Story classes are no longer experimental, and entities can
      declare a text avatar.

- [x] **[v0.17 — Bodies](https://github.com/storyfeed/storyfeed/milestone/2)** *(tagged `v0.17.0`)*. A base class every body builds
      on, links that own their behaviour, `CallToAction` and `Table` bodies,
      time ranges, and a body's own `$meta` for rendering concerns. The array
      source for static content arrived early.

- [x] **[v0.18 — Feed sources](https://github.com/storyfeed/storyfeed/milestone/22)** *(tagged `v0.18.0`)*. A role can be filled by
      something with no model behind it, an `Image` can carry its own picture,
      bodies can show any `feedMedia()` slot, every entity has an avatar, and
      `STORYFEED_SNAPSHOTS=sync` shows `toFeed()` changes on reload. Tables from
      before 0.13 can be upgraded in place.

- [x] **[v0.19 — Read path at scale](https://github.com/storyfeed/storyfeed/milestone/3)** *(tagged `v0.19.0`)*. Scoped feeds read in
      feed order from the participants index, and a Live page takes 9 queries
      instead of 23: under 100 ms at a million activities on every supported
      database, with nightly benchmark gates at 1M and 3M. A group's members can
      be read past its first page, group ids became `Anchor` values, and closed
      burst windows are swept.
- [x] **[v0.20 — Composed feeds](https://github.com/storyfeed/storyfeed/milestone/23)** *(tagged `v0.20.0`)*. `Storyfeed::compose()`
      builds a feed by hand from data an app already has, such as a project
      showcase, a list of talks or a record's status progression, as `Entry`
      items: dated or dateless, newest first or in the order given, with inline
      headlines. Nothing is stored, and the kits draw it like any other feed.

## The countdown to 1.0

Each version below is a GitHub milestone holding its concrete issues. Issue
titles start with the version they target, such as `[0.17]`. Milestones show
the current plan: items may be reordered, moved to a later version or dropped as
the work teaches us, and a dropped item is closed as not planned with its reason.

- [ ] **[v0.21 — Context groups and new bodies](https://github.com/storyfeed/storyfeed/milestone/4).**
      A composed feed can fold the entries that share a context into one
      group, such as a release's changes or an album's photos, and code and
      callout bodies arrive.
- [ ] **[v1.0 — Stable](https://github.com/storyfeed/storyfeed/milestone/5).** One
      consolidated schema, a versioning and support policy, and a release
      candidate before 1.0.0. Payload v1 is declared stable with the release
      candidate; until then the payload may still change.

Curation, which activities group together and when, keeps improving behind the
stable payload contract and stays labelled experimental.

## After 1.0

Planned for the [1.x line](https://github.com/storyfeed/storyfeed/milestone/6), in
no particular order: a notifications bridge, Story auto-discovery, a public demo
API, more feed sources (Markdown, Laravel Pennant), sections other than dates,
and multi-tenancy exploration.

The rest of Activity Streams 2.0, each additive to Payload v1: replies
(`inReplyTo`), tags and mentions, questions (`oneOf`, `anyOf`, `closed`) and
place coordinates. Addressing
(`to`, `cc`, `bto`, `bcc`, `audience`) arrives with delivery, long-range,
alongside ActivityPub.

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
