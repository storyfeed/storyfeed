# Storyfeed — working notes for agents

Laravel activity-feed package by Tey Labs. Core (this repo, MIT, headless) emits
a versioned payload contract; renderer packages consume it. **Packaging model
settled 2026-08-18:** `storyfeed/ui` (Vue/Inertia + Blade) is free and MIT on
Packagist — gating it made the end-to-end story untellable, and sponsorship funds
its pace rather than access. Activity Streams 2.0 at the serialization
boundary.

## Key documents (gitignored — local only until milestones publish them)

- `SPEC.md` — the design spec; settled decisions live here
- `docs/payload.md` — the Payload v1 contract (freezes at v0.3)
- `docs/stories.md` — the Story layer (shipped; no longer experimental)
- `docs/grouping.md` — curation, the remaining R&D track (explicitly unsettled)
- `docs/verbs.md` — the verb ladder (strings → FeedVerb enum → Story classes)
- `docs/parties.md` — named participants with no app model (any role)
- `docs/feeds.md` — named feeds: verb allowlists a doctor check can see
- `docs/roadmap.md` — phasing (v0.1–v0.4 ✅ → v0.5 DX/Newsroom → v0.6 AS2.0 → …)
- `ROADMAP.md` — the slim public roadmap (tracked); keep it in sync at milestones

## The journal (maintain this!)

`docs/journal/` is an as-it-happened decision log — source material for
write-ups and the project's design history. **After every milestone or
significant decision cluster, add a numbered entry** capturing: what was
decided, the tension (what lost and why), and notable moments (surprises,
traps, reversals).
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
- **Unknown methods are errors, never features.** `__call` verb magic was
  removed at v0.4 on DX grounds (see journal 006). No behaviour hides behind
  `__call`, with one exception: Laravel's `Macroable` on the `Storyfeed\Support`
  readers, where every method is registered explicitly and anything else still
  throws (ruled 2026-09-26, following Laravel's own helpers).
- **Anonymous ≠ system.** A null actor means the actor is genuinely unknown;
  a named non-model participant is a `Party` (any role). Never conflate them.
- Package-owned morph aliases must resolve via `Support\MorphResolver`,
  independently of the app's morph map — the trickle counts activities with
  uncached, unresolvable roles by default and soft-deletes them only when
  `storyfeed.trickle.prune` is enabled or `storyfeed:trickle --prune` is used.
- Verbs stay free-form strings in storage, except a verb may not contain a dot. Enums are an authoring convenience;
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

- `main` is the trunk and tags are releases; no develop branch. Changes reach
  `main` through pull requests, stacked with GitHub's `gh stack`
  (github/gh-stack) when one change builds on another. CI runs on every PR,
  including storyfeed/ui's core-main cells, before anything merges; the lead
  merges a green stack with `gh stack merge <n> --squash --yes` (atomic, and
  it can merge just the bottom PR). Lanes open PRs and never merge them.
  Adopted 2026-10-10 after direct pushes broke ui and core main in one night.
  Short-lived `rnd/*` branches for curator experiments
  (experiment in workbench/, throw away freely).
- A release-prep PR for any version that adds or changes public API also updates
  the Boost guideline (`resources/boost/guidelines/core.blade.php`) and the skill
  (`resources/boost/skills/storyfeed-development/SKILL.md`), which agents read
  instead of the docs. `tests/BoostSkillTest.php` fails when a facade method or
  body type is named in neither; allow-list only genuine internals, with a reason.
- Checks before commit: `vendor/bin/pest`, `vendor/bin/phpstan analyse`,
  `vendor/bin/pint`.
- CI matrix: PHP 8.4/8.5 × Laravel 12/13 × prefer-lowest/stable × **ubuntu and
  windows**, plus one ubuntu/8.5/L13/prefer-stable cell that runs `--parallel`,
  and ubuntu/8.5/L13/prefer-stable MySQL 8, PostgreSQL 18 and MariaDB 12.3 cells
  with four workers — **twenty test jobs, eight of them Windows.** Windows is supported
  and the cells are real: they were red for days on a CRLF-only defect nobody
  saw, because local checks are one cell of twenty. The parallel cell exists
  because tests sharing a file across workers passed serially for weeks.
  Jasper ruled that every supported database engine belongs in the matrix;
  the MySQL LIKE escape defect (1052) passed SQLite-only CI and shows why.
  **`vendor/bin/pest` passing locally is not "green" — check
  `gh run list --repo storyfeed/storyfeed` after pushing.**
  When touching composer constraints, dry-run the combos locally
  (`COMPOSER=composer.citest.json composer update --prefer-lowest --dry-run`).

## Tests stay in their lane

Each package tests only what it implements (Jasper, 2026-10-11): "everyone stays in their lane". Core's tests cover core's output: the payload, storage and commands. They never assert how storyfeed/ui or storyfeed/filament render it, and never read another package's source or class names. A renderer's output is tested in that renderer's repo.

## Remote agents (orbs) and pull requests

These apply to any agent working from a fresh clone, such as an Amp orb.

- Read the issue you're working on, `README.md` and the relevant `docs/` first. The gitignored documents listed under "Key documents" aren't in a fresh clone; work from the tracked code, tests and docs.
- `CLIENT-NAMES.md` is local only. Without it, don't touch prior-implementation source and don't write about earlier implementations at all.
- Gates before opening or updating a PR: `vendor/bin/pest`, `vendor/bin/phpstan analyse` and `vendor/bin/pint`. A local pass is one cell of twenty: CI runs PHP 8.4/8.5 × Laravel 12/13 × lowest/stable on Ubuntu and Windows, plus MySQL, PostgreSQL and MariaDB. Every check must be green on the PR's final commit.
- Every change gets tests that fail without it. Add an entry under `## Unreleased` in `CHANGELOG.md`, ending with the issue number, e.g. `(#177)`.
- Work on a branch from the latest `origin/main`; never commit to `main`.
- Commit as `Jasper Tey <jasper@teylabs.com>`, with imperative, sentence-case subjects.
- **No attribution of any kind:** no `Co-authored-by`, no agent thread IDs, no "Generated with" lines and no links to agent sessions, in commits, PR titles or PR bodies.
- Open pull requests as **drafts**, titled like a commit subject. The body says what changed, how it was verified, and `Closes #N`.
- Never merge, push to `main`, tag or release. The maintainer does that.
- This repository is public: never include secrets, local absolute paths, private app names or internal notes.
