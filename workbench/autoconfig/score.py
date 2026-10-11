"""Exact vocabulary scoring; this deliberately does not imply runtime parity."""
from pathlib import Path
import json

ROOT = Path('/Users/jasper/Dev/projects/rnd-autoconfig-apps/evidence')
key = json.loads((ROOT / 'answer-key.json').read_text())
expected = {(k['object'], k['verb']) for k in key}
report = {}
for label in ['A', 'A2', 'B', 'C']:
    path = ROOT / f'{label}-candidates.json'
    if not path.exists():
        continue
    rows = json.loads(path.read_text())
    proposed = {(r['object'], r['verb']) for r in rows}
    matched = expected & proposed
    report[label] = {'expected': len(expected), 'proposed': len(proposed), 'matched': len(matched),
                     'precision': len(matched) / len(proposed) if proposed else 0,
                     'recall': len(matched) / len(expected),
                     'matched_pairs': sorted(matched), 'extra_pairs': sorted(proposed - expected),
                     'missing_pairs': sorted(expected - proposed),
                     'minimum_vocabulary_edits': len(proposed - expected) + len(expected - proposed),
                     'signals': {signal: {'proposed': len({(r['object'], r['verb']) for r in rows if r['signal'] == signal}),
                                           'matched': len({(r['object'], r['verb']) for r in rows if r['signal'] == signal} & expected)}
                                 for signal in sorted({r['signal'] for r in rows})}}
(ROOT / 'scores.json').write_text(json.dumps(report, indent=2))
print(json.dumps({k: {f: v[f] for f in ['expected', 'proposed', 'matched', 'precision', 'recall', 'minimum_vocabulary_edits', 'signals']} for k, v in report.items()}, indent=2))
