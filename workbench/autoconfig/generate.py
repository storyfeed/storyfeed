"""A deterministic, source-only baseline. Outputs go exclusively to app copies."""
from pathlib import Path
import json, re, subprocess, sys

ROOT = Path('/Users/jasper/Dev/projects/rnd-autoconfig-apps')
EVIDENCE = ROOT / 'evidence'

def snake(value):
    return re.sub(r'(?<!^)(?=[A-Z])', '_', value).lower()

def php(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"

def write_new(path, text):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('x') as stream:
        stream.write(text)

def generate(label, candidates):
    root = ROOT / label
    if root.exists():
        raise RuntimeError('Refusing to overwrite an experiment')
    subprocess.run(['cp', '-cR', str(ROOT / 'stripped'), str(root)], check=True)
    models = sorted({r['object'] for r in candidates if r['object'] != '*'})
    lines = ['<?php', ''] + ['use App\\Models\\' + m + ';' for m in models] + ['use Storyfeed\\Facades\\Story;', '']
    seen = set()
    for row in candidates:
        row['name'] = 'inferred.' + row['object'] + '.' + row['verb']
        pair = row['object'], row['verb']
        if pair not in seen:
            # Wildcard is explicit when the event accepts an interface.
            obj = php('*') if row['object'] == '*' else row['object'] + '::class'
            lines += [f"Story::for({obj})->verb({php(row['verb'])})", f"    ->headline({php(row['headline'])})", f"    ->name({php(row['name'])});", '']
            seen.add(pair)
        if row['signal'] == 'event':
            event = row['event']
            short = event.split('\\')[-1]
            # Nested listeners are not discovered by every Laravel application's
            # configured discovery path. Put these in app/Listeners directly.
            code = f'''<?php

namespace App\\Listeners;

use {event};

class Record{short}ToFeed
{{
    public function handle({short} $event): void
    {{
        // Review actor, transaction timing and duplicate dispatch before enabling.
        story({php(row['name'])}, $event->{row['property']})
            ->actor({row.get('actor', 'null')})
            ->publish();
    }}
}}
'''
            write_new(root / f'app/Listeners/Record{short}ToFeed.php', code)
        elif row['signal'] == 'model':
            # Registration is in the new feed file, already loaded by Storyfeed.
            model = row['object']
            event = row['event']
            lines += [f'{model}::{event}(function ({model} $model): void {{',
                      f"    story({php(row['name'])}, $model)->actor(null)->publish();", '});', '']
    write_new(root / 'routes/feed.php', '\n'.join(lines))
    (EVIDENCE / f'{label}-candidates.json').write_text(json.dumps(candidates, indent=2))
    return root

def baseline(inventory):
    candidates = []
    for row in inventory['classes']:
        if not row['file'].startswith('app/Events/'):
            continue
        props = [(key, value.strip('\\?')) for key, value in row['properties'].items() if value and value.strip('\\?').startswith('App\\')]
        if not props:
            continue
        prop, model = props[0]
        short = row['class'].split('\\')[-1]
        noun = model.split('\\')[-1]
        suffix = short[len(noun):] if short.startswith(noun) else short
        verb = snake(noun) + '.' + snake(suffix)
        candidates.append({'object': '*', 'verb': verb, 'headline': ':object: ' + re.sub(r'(?<!^)(?=[A-Z])', ' ', suffix).lower(),
                           'signal': 'event', 'event': row['class'], 'property': prop, 'source': row['file'], 'line': row['line'], 'confidence': 'review'})
    # Deliberately broad baseline: shows the precision cost of treating persistence
    # as product intent. It is a measured approach, not a production recommendation.
    for row in inventory['classes']:
        if not row['file'].startswith('app/Models/') or row['abstract']:
            continue
        short = row['class'].split('\\')[-1]
        for event, verb, past in [('created', 'create', 'created'), ('updated', 'update', 'updated'), ('deleted', 'delete', 'deleted')]:
            candidates.append({'object': short, 'verb': verb, 'headline': ':object was ' + past, 'signal': 'model', 'event': event, 'source': row['file'], 'line': row['line'], 'confidence': 'low'})
        if any(t.endswith('SoftDeletes') for t in row['traits']):
            candidates.append({'object': short, 'verb': 'restore', 'headline': ':object was restored', 'signal': 'model', 'event': 'restored', 'source': row['file'], 'line': row['line'], 'confidence': 'low'})
    return candidates

if __name__ == '__main__':
    inventory = json.loads((EVIDENCE / 'inventory.json').read_text())
    candidates = baseline(inventory)
    generate('A', candidates)
    print(json.dumps({'proposals': len(candidates), 'events': sum(c['signal'] == 'event' for c in candidates), 'models': sum(c['signal'] == 'model' for c in candidates)}))
