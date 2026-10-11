# Autoconfig experiment

Local R&D on `rnd/autoconfig`; no production command added. The consumer proposal
and complete interpretation live in `storyfeed-docs/docs/rnd/autoconfig.md`.

App copies and private evidence are outside repositories at
`/Users/jasper/Dev/projects/rnd-autoconfig-apps/`. Never point a mutating script at
a real app. `strip.php` refuses the immutable `baseline`; `rewrite.php` permits
only the B copy. Generation refuses to overwrite an experiment or file.

Use Herd PHP 8.4+ and the lane's Composer development dependencies, including
nikic/php-parser. Python scripts pin this machine's Herd binary. No dependency
constraints were added to core. The scanner does not boot the inspected app.

## First-run sequence

A baseline copy must already exist, made with `cp -cR` from the clean source.
Do not rerun preparation over the existing evidence. Preserve it as the record
of this experiment; use a new experiment root for another dataset.

```sh
python3 workbench/autoconfig/prepare.py
python3 workbench/autoconfig/generate.py
```

Prepare B with `generate('B', [])` from `generate.py`, then run:

```sh
python3 workbench/autoconfig/run_b.py
python3 workbench/autoconfig/propose_c.py
python3 workbench/autoconfig/score.py
```

A2 uses the A candidates whose `signal` is `event`, passed to
`generate('A2', candidates)`. C is a saved, **unblinded agent proposal**. Rerunning
that file replays those choices; it does not perform new AI inference.

For B, `rewrite.php COPY` returns a JSON edit plan without writing. `run_b.py`
asserts app-source hashes did not change, saves the unified diff, applies with
`--apply`, checks all affected PHP files and asserts a second run is empty.
The current B is already rewritten, so rerunning `run_b.py` would replace the
original evidence with an empty diff. Inspect existing `evidence/B.patch` instead.

## Runtime copies

`*-runtime` copies restore original enums, Stories and app feed support classes
solely to satisfy remaining UI references. They retain stripped event publishers
and generated routes/listeners. The lane's core src/config/database/resources/stubs
are copied into their vendor directory; the originally installed package was older.
The isolated .env uses SQLite memory, array mail/cache/session, sync queues and no
external credentials. These copies are distinct from generation inputs.

```sh
php workbench/autoconfig/runtime-smoke.php \
    /Users/jasper/Dev/projects/rnd-autoconfig-apps/C-runtime
```

A2 and C compile and pass two real-event/Storyfeed-fake dispatch checks. Broad A
fails on an unmapped model, a real failure preserved in evidence. B inherits A's
configuration. No full application database, transaction or retry parity is claimed.

## Evidence

`evidence/answer-key.json` contains 69 exact object-constraint/verb pairs, original
headlines and static publication references. The latter are source references,
not a control-flow proof of each dispatch. `answer-feed.php` and
`answer-story-headlines.json` preserve the original authoring surface.

`inventory.json` contains all parsed class/method/property facts and source signals;
`inventory-agent.json` is a smaller filtered view. Neither reads application data.
`stripped.diff` records the extraction, and `*-candidates.json`, `scores.json`,
`B.patch`, runtime registry output and smoke logs preserve the comparisons.

Scores are exact vocabulary overlap, never a claim that matching candidates have
correct data, actors, objects at runtime, commit timing or rendered grammar. Minimum
edit counts are symmetric set differences, not measured human effort. C's billing
cost and token use are unknown because it was not a separately metered invocation.
