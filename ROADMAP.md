# Roadmap

Storyfeed is being built in the open, on the road to a stable 1.0. This is the
high-level plan; detailed design documents will be published as the milestones land.

## Toward 1.0

- [ ] **v0.1 — Foundation.** Schema (activities, entity snapshots, groupings), the
      fluent recording API (`Storyfeed::activity()->actor($user)->confirm($delivery)->publish()`),
      snapshot-backed entities, workbench + test suite.
- [ ] **v0.2 — Read model.** Feed query builder, scoped feeds, cursor pagination,
      the self-describing JSON payload (headline templates, icons, linked entities —
      renderers need zero domain knowledge).
- [ ] **v0.3 — Payload contract.** The feed payload shape is documented and frozen as
      a versioned contract, including grouped-activity nodes
      ("Bob, Sally, and 3 others uploaded files to Project X").
- [ ] **v0.4 — DX & tooling.** Test fakes, grammar-coverage assertions,
      `storyfeed:doctor`, documentation, first production adoption.
- [ ] **v0.5 — Activity Streams 2.0.** Spec-conformant JSON-LD serialization
      (`Activity`, `OrderedCollection`) validated against the W3C test documents;
      declarative `Story` classes (experimental).
- [ ] **v1.0 — Stable.** Frozen payload contract, semver promise, both authoring
      APIs (fluent builder + `Story` classes).

## Beyond 1.0

- Smarter feed curation — dynamic, social-style activity grouping that improves
  behind the stable payload contract.
- Story auto-discovery.
- **`storyfeed/ui`** — a companion package of polished, pre-built feed components
  for those who'd rather not build their own renderer.
- Long-range: ActivityPub (the architecture already mints stable IRIs and speaks
  AS2.0 for this reason).

## Design principles

1. **Curated, not logged.** A feed is deliberate storytelling about your domain —
   not an audit trail of every model save.
2. **Headless and self-describing.** The core emits a versioned payload that fully
   describes every item; any renderer can consume it.
3. **Activity Streams 2.0 under the hood.** Laravel-native storage and DX, W3C
   semantics at the serialization boundary.
