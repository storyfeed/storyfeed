# Storyfeed — working notes for Claude

Laravel activity-feed package by Tey Labs. Core (this repo, MIT, headless) emits
a versioned payload contract; renderer packages consume it. **Packaging model
settled 2026-08-18:** `storyfeed/ui` (Vue/Inertia + Blade) is free and MIT on
Packagist — gating it made the end-to-end story untellable, and sponsorship funds
its pace rather than access. `storyfeed/filament` is a **separate repo,
commercial from its first commit** (~$49 one-time, Anystack licence keys +
private Composer endpoint). This narrows the 2026-08-14 all-MIT-UI decision for
the Filament adapter only; see journal 035. Activity Streams 2.0 at the
serialization boundary.

## Key documents (gitignored — local only until milestones publish them)

- `SPEC.md` — the design spec; settled decisions live here
- `docs/payload.md` — the Payload v1 contract (freezes at v0.3)
- `docs/stories.md`, `docs/grouping.md` — the two R&D tracks (explicitly unsettled)
- `docs/verbs.md` — the verb ladder (strings → FeedVerb enum → Story classes)
- `docs/parties.md` — named participants with no app model (any role)
- `docs/feeds.md` — named feeds: verb allowlists a doctor check can see
- `docs/roadmap.md` — phasing (v0.1–v0.4 ✅ → v0.5 DX/Newsroom → v0.6 AS2.0 → …)
- `ROADMAP.md` — the slim public roadmap (tracked); keep it in sync at milestones

## The journal (maintain this!)

`docs/journal/` is an as-it-happened decision log — source material for a
future conference talk. **After every milestone or significant decision
cluster, add a numbered entry** capturing: what was decided, the tension
(what lost and why), and talk-worthy moments (surprises, traps, reversals).
Write it while it's fresh; never reconstruct after the fact. See
`docs/journal/README.md` for conventions.

## Hard rules

- The payload contract (`docs/payload.md`) is the product boundary: no
  breaking changes once frozen; cursors stay opaque; group-node shape is
  contract, curation policy is not.
- Core stays headless — no view/UI dependencies (arch-test enforced). Route
  "basic component" energy to a plain Blade example in docs, never into src/.
- Morph aliases everywhere: compare with `getMorphClass()`, never `get_class()`.
- Activities are never hidden by the read path — degrade gracefully.
- **No magic methods.** `__call` verb magic was removed at v0.4 on DX grounds
  (see journal 006); unknown methods must be errors, not features.
- **Anonymous ≠ system.** A null actor means the actor is genuinely unknown;
  a named non-model participant is a `Party` (any role). Never conflate them.
- Package-owned morph aliases must resolve via `Support\MorphResolver`,
  independently of the app's morph map — the trickle deletes activities whose
  role alias won't resolve.
- Verbs stay free-form strings in storage. Enums are an authoring convenience;
  AS2.0 enums are pure vocabulary transcriptions that never throw and never
  gate validation. Unknown/extension types are preserved verbatim, never dropped.
- Support policy: rolling current + previous Laravel major; PHP ^8.4 only,
  lean into 8.4 idioms. Dev tooling may dual-constrain (Pest ^4||^5) where the
  older Laravel lane's harness requires it.
- No client names in anything tracked/public — collective "past experience"
  framing only. This repo is public, so the list itself is not written here:
  it lives in `CLIENT-NAMES.md` (gitignored, local only). **Read it before
  touching prior-implementation source or writing about it**, so you can
  recognise a name when you see one.

## Workflow

- Trunk-based: commit to `main`, tags are releases, no develop branch.
  Short-lived `rnd/*` branches for Story-layer and curator experiments
  (experiment in workbench/, throw away freely).
- Checks before commit: `vendor/bin/pest`, `vendor/bin/phpstan analyse`,
  `vendor/bin/pint`.
- CI matrix: PHP 8.4/8.5 × Laravel 12/13 × prefer-lowest/stable. When touching
  composer constraints, dry-run all four combos locally
  (`COMPOSER=composer.citest.json composer update --prefer-lowest --dry-run`).
