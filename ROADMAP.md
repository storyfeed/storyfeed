# Roadmap

Storyfeed is being built in the open, on the road to a stable 1.0. This is the
high-level plan; detailed design documents will be published as the milestones land.

**Versions are milestones, not dates**, and the milestones are ordered by what
makes the package genuinely usable rather than by what makes a version number
land. Nothing on this list is scheduled against a conference date: the talk this
work leads to is a demonstration of a working package, not a launch event, so
1.0 arrives when the contract has earned it.

## Toward 1.0

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
- [ ] **v0.7 — Authoring DX (unreleased, on `main`).** `Story` classes — one class
      per activity type instead of seven registration sites — with `make:story`,
      `PublishesToFeed` for domain events, a structured `doctor` report you can
      assert on in CI, `storyfeed:stories`, `storyfeed:cache`, and strict grammar
      in local/testing.
- [ ] **v0.8 — Audience scoping.** Named feed presets registered once
      (`Storyfeed::feeds([...])`, entered as `Storyfeed::feed('customer')`), with
      `->only()` / `->except()` verb allowlists and a `FeedCoverage` doctor check so a
      verb that belongs to no audience fails CI rather than leaking. In the MIT core,
      deliberately: nothing that makes a feed safe to show belongs in a paid package.
- [ ] **v1.0 — Stable.** Frozen payload contract, semver promise, both authoring
      APIs (fluent builder + `Story` classes).

## Beyond 1.0

- Smarter feed curation — dynamic, social-style activity grouping ("Bob, Sally and 3
  others uploaded files to Project X") that improves behind the stable payload
  contract. Three per-view read modes — `->log()` (atomic log), `->live()`
  (classic repeat grouping), and `->summary()` multi-axis grouping as the
  **default** — and the curation policy is free to keep evolving after 1.0
  because it was never part of the contract.
- Full-history scale — feeds that stay fast at millions of activities without
  pruning (time-partitioned storage, warm/cold tiering behind the opaque cursor).
- Story auto-discovery.
- **`storyfeed/ui`** — a free, MIT companion package of pre-built feed components
  for those who'd rather not build their own renderer. Vue/Inertia and plain Blade
  first; Livewire and React follow as sponsorship allows the time (see Sponsoring
  in the README).
- **`storyfeed/filament`** — the Filament plugin, in its own repository and
  **commercial from its first commit** (~$49 one-time, sold through Anystack).
  It is the one paid piece; the core and `storyfeed/ui` stay MIT and render a
  complete feed without it. Why one adapter is priced differently — and why that
  narrowing of an earlier all-MIT statement is written down rather than quietly
  applied — is in the README under *How this is packaged*.
- A public demo API, so any frontend — Nuxt, Next, SvelteKit, mobile — can be
  pointed at a live Storyfeed and render it however it likes.
- Laravel notifications, bridged both ways — a notification class that
  `implements PublishesToFeed` also publishes its fact to the feed (same
  interface events use, no channel plumbing at the call site), and feed
  activities notifying subscribers via the `ActivityPublished` event.
- Long-range: ActivityPub (the architecture already mints stable IRIs and speaks
  AS2.0 for this reason).

## Design principles

1. **Curated, not logged.** A feed is deliberate storytelling about your domain —
   not an audit trail of every model save.
2. **Headless and self-describing.** The core emits a versioned payload that fully
   describes every item; any renderer can consume it.
3. **Activity Streams 2.0 under the hood.** Laravel-native storage and DX, W3C
   semantics at the serialization boundary.
