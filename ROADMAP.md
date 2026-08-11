# Roadmap

Storyfeed is being built in the open, on the road to a stable 1.0. This is the
high-level plan; detailed design documents will be published as the milestones land.

## Toward 1.0

- [x] **v0.1 — Foundation.** Schema (activities, entity snapshots, groupings), the
      fluent recording API (`Storyfeed::activity()->actor($user)->confirm($delivery)->publish()`),
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
- [ ] **v0.5 — DX & tooling.** Test fakes, grammar-coverage assertions,
      documentation, first production adoption — and **the Newsroom**: a deployed,
      living showcase app with simulated activity you can watch and poke.
      Possibly `Storyfeed::capture([Post::class])`: register a few models and get
      a working feed with no recording calls, then eject the real code.
- [ ] **v0.5 — Activity Streams 2.0.** Spec-conformant JSON-LD serialization
      (`Activity`, `OrderedCollection`) validated against the W3C test documents;
      declarative `Story` classes (experimental).
- [ ] **v1.0 — Stable.** Frozen payload contract, semver promise, both authoring
      APIs (fluent builder + `Story` classes).

## Beyond 1.0

- Smarter feed curation — dynamic, social-style activity grouping that improves
  behind the stable payload contract.
- Full-history scale — feeds that stay fast at millions of activities without
  pruning (time-partitioned storage, warm/cold tiering behind the opaque cursor).
- Story auto-discovery.
- **`storyfeed/ui`** — a companion package of polished, pre-built feed components
  for those who'd rather not build their own renderer. Vue/Inertia first; more
  adapters (Livewire, Blade, Filament, React) to follow.
- A public demo API, so any frontend — Nuxt, Next, SvelteKit, mobile — can be
  pointed at a live Storyfeed and render it however it likes.
- Long-range: ActivityPub (the architecture already mints stable IRIs and speaks
  AS2.0 for this reason).

## Design principles

1. **Curated, not logged.** A feed is deliberate storytelling about your domain —
   not an audit trail of every model save.
2. **Headless and self-describing.** The core emits a versioned payload that fully
   describes every item; any renderer can consume it.
3. **Activity Streams 2.0 under the hood.** Laravel-native storage and DX, W3C
   semantics at the serialization boundary.
