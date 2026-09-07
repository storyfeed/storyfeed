# PHPStan rule-test cache warning (W68)

Investigated 2026-09-07 at `de25c2f`, using PHP 8.5.8, PHPStan 2.2.8,
Pest 5.0.5, PHPUnit 13.3.0 and Collision 8.9.5 on macOS.

## Reproduction

An empty temporary directory reproduces the warning without concurrent runs,
cache deletion during execution, or another test running first:

```sh
w68_tmp=$(mktemp -d)
TMPDIR="$w68_tmp" vendor/bin/pest tests/Static/FeedMakeArityRuleTest.php --order-by=random --random-order-seed=6802
```

This prints the missing-file warning from `FileCacheStorage.php:51`, passes the
assertion, and exits 0. Repeating against the same warmed directory is quiet.
Running `vendor/bin/phpunit` with the same arguments and a separate empty
temporary directory is also quiet and exits 0. Adding `--debug` (seed 6804)
shows PHPStan's cache warnings explicitly marked `suppressed using operator`.

The full Pest suite with a fresh directory and `--order-by=random
--random-order-seed=6803 --compact` reports **910 passed, 1 warning, 3000
assertions**, exit 0. All 911 tests execute. No prewarming is needed for success.

The initial cold reproduction reported seed 6801 but omitted `--order-by=random`.
Pest additionally warned that the seed option requires random order, exiting 1.
That exit is not evidence that the cache warning fails the suite. Always pass
both options when capturing a reproducible random seed with this Pest version.

## Diagnosis and decision

PHPStan's `PHPStanTestCaseTrait::getContainer()` uses
`sys_get_temp_dir().'/phpstan-tests'`. Its `FileCacheStorage::load()` deliberately
uses `@include` and returns `null` when no `CacheItem` is loaded: an expected cold
cache miss. Our test uses the ordinary `RuleTestCase` API. The single-test,
single-process reproduction rules out shared-cache concurrency as a necessary
condition for this symptom.

The display discrepancy is upstream in Collision's PHPUnit printer:
`DefaultPrinter::testPhpWarningTriggered()` adds a warning to its display state
without checking the event's `wasSuppressed()` flag. PHPUnit's result collector
instead applies its `IssueFilter`, explaining the quiet PHPUnit result and
Pest's successful exit despite the displayed warning. The installed source and
the cold PHPUnit debug trace establish this; it is not a Storyfeed assertion
defect or evidence of a failed cache write.

Related [PHPUnit issue 6855](https://github.com/sebastianbergmann/phpunit/issues/6855)
discusses PHPStan cache misses but concerns replayed data-provider warnings.
Its fix is already present in our PHPUnit 13.3.0; our warning occurs in the test
method. Do not misidentify that issue as this defect's fix.

Keep the assertions, analysis configuration and CI unchanged. No supported
PHPStan harness setting was identified that corrects Collision's event handling.
A per-run directory still starts cold, and exporting `TMPDIR` plus prewarming
merely avoids the expected misses. Neither addresses the reporting defect.
Do not add warning suppression, a baseline, printer overrides or vendor patches.
The appropriate follow-up is an upstream Collision reporting fix with coverage
for suppressed versus unsuppressed events; no upstream issue was filed by W68.

The brief's binary "ours or PHPStan's" misses the reporting adapter. Its
intermittency description is consistent with cache state, but a cold cache makes
the symptom deliberate. Windows CI was supplied evidence in the brief, not
retested here; the diagnosis above is verified on the installed local versions.
