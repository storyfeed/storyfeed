from pathlib import Path
import json,subprocess,difflib,hashlib,shutil
ROOT=Path('/Users/jasper/Dev/projects/rnd-autoconfig-apps'); E=ROOT/'evidence'
PHP='/Users/jasper/Library/Application Support/Herd/bin/php'
script=Path(__file__).with_name('rewrite.php').resolve()
b=ROOT/'B'
if (E/'B-validation.json').exists():
 raise SystemExit('B already applied; inspect B.patch or run rewrite.php directly for an idempotence check')
# B extends A's vocabulary and adds reviewable edits to existing app code.
shutil.copy2(ROOT/'A/routes/feed.php',b/'routes/feed.php')
for p in (ROOT/'A/app/Listeners').glob('Record*ToFeed.php'): shutil.copy2(p,b/'app/Listeners'/p.name)
def hashes(): return {str(p.relative_to(b)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (b/'app').rglob('*.php')}
before=hashes()
r=subprocess.run([PHP,str(script),str(b)],capture_output=True,text=True,check=True)
assert hashes()==before, 'dry-run mutated app'
p=json.loads(r.stdout); (E/'B-dry-run.json').write_text(r.stdout)
(E/'B.patch').write_text(''.join(''.join(difflib.unified_diff(x['before'].splitlines(True),x['after'].splitlines(True),'a/'+x['file'],'b/'+x['file'])) for x in p['patches']))
inline=[dict(e,source=x['file'],signal='inline',headline=':object was '+{'update':'updated','delete':'deleted','restore':'restored'}[e['verb']]) for x in p['patches'] for e in x['edits']]
rows=json.loads((E/'A-candidates.json').read_text())+inline
(E/'B-candidates.json').write_text(json.dumps(rows,indent=2))
subprocess.run([PHP,str(script),str(b),'--apply'],check=True,stdout=subprocess.DEVNULL)
for x in p['patches']:
 assert (b/x['file']).read_text()==x['after']
 subprocess.run([PHP,'-l',str(b/x['file'])],check=True,stdout=subprocess.DEVNULL)
second=json.loads(subprocess.check_output([PHP,str(script),str(b)],text=True))
assert second['patches']==[], 'rewrite not idempotent'
result={'dry_run_changed_files':0,'applied_files':len(p['patches']),'insertions':len(inline),'lint_failures':0,'second_run_insertions':0,'inline_unique_pairs':len({(r['object'],r['verb']) for r in inline})}
(E/'B-validation.json').write_text(json.dumps(result,indent=2)); print(json.dumps(result))
