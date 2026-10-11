"""Prepare isolated app copies and an independently stored answer key."""
from pathlib import Path
import subprocess, re, json, shutil, os
ROOT=Path('/Users/jasper/Dev/projects/rnd-autoconfig-apps')
BASE=ROOT/'baseline'
HERE=Path(__file__).resolve().parent
PHP='/Users/jasper/Library/Application Support/Herd/bin/php'
ROOT.chmod(0o700)
art=ROOT/'evidence'
if (ROOT/'stripped').exists() or (art/'answer-key.json').exists():
 raise SystemExit('Existing experiment evidence; use a new root rather than overwrite it')
art.mkdir(exist_ok=True)
key=[]
for enum in ['ClientActivity','DocumentActivity','EngagementActivity']:
 text=(BASE/f'app/Enums/{enum}.php').read_text()
 for case,verb in re.findall(r"case\s+(\w+)\s*=\s*'([^']+)'",text):
  key.append({'object':'*','verb':verb,'enum':enum,'case':case,'headlines':[],'publishers':[]})
feed=(BASE/'routes/feed.php').read_text()
classes={p.stem:p.read_text() for p in (BASE/'app/Stories').glob('*.php')}
current=None
for line in feed.splitlines():
 m=re.search(r'\$story = new (\w+)',line)
 if m: current=m[1]
 m=re.search(r'\$story->(\w+)\(Story::verb\((\w+)::(\w+)\)',line)
 if m:
  method,enum,case=m.groups()
  body=re.split(r'public function '+method+r'\(',classes[current])[1].split('public function ')[0]
  headline=re.search(r"->headline\('([^']*)'\)",body)
  for k in key:
   if k['enum']==enum and k['case']==case:
    k['headlines']=[headline[1]] if headline else []
    k['declaration']=f'app/Stories/{current}.php::{method}'
for enum, case, story in re.findall(r'Story::verb\((\w+Activity)::(\w+), (\w+)::class\)', feed):
 for k in key:
  if k['enum']==enum and k['case']==case:
   k['headlines']=[re.search(r"->headline\('([^']*)'\)",classes[story])[1]]
   k['declaration']=f'app/Stories/{story}.php::__invoke'
for model,verbs in [('ClauseTemplate',['add','update','retire','restore']),('ClauseVariant',['create','update','remove','restore'])]:
 for verb in verbs:
  body=classes[model+'Story'].split('public function '+verb+'(')[1].split('public function ')[0]
  headline=re.search(r"->headline\('([^']*)'\)",body)
  key.append({'object':model,'verb':verb,'headlines':[headline[1]] if headline else [],'publishers':[],'declaration':f'app/Stories/{model}Story.php::{verb}'})
for p in (BASE/'app').rglob('*.php'):
 text=p.read_text()
 for k in key:
  needle=k.get('enum','Act')+'::'+k.get('case',k['verb'].capitalize())
  if needle in text and ('PendingActivity' in text or '->publish(' in text):
   k['publishers'].append({'file':str(p.relative_to(BASE)),'line':text[:text.index(needle)].count('\n')+1,'mechanism':'event' if 'toFeedActivity' in text else 'inline'})
(art/'answer-key.json').write_text(json.dumps(key,indent=2))
# Preserve original declarations/headlines for dynamic classes too.
shutil.copy2(BASE/'routes/feed.php',art/'answer-feed.php')
(art/'answer-story-headlines.json').write_text(json.dumps({n:re.findall(r"->headline\('([^']*)'\)|return '([^']*)';",s) for n,s in classes.items()},indent=2))
clean=ROOT/'stripped'
if clean.exists(): raise SystemExit('Refusing to overwrite stripped copy')
subprocess.run(['cp','-cR',str(BASE),str(clean)],check=True)
# Never boot with real credentials, caches, package symlinks or shared test DB.
for p in clean.glob('.env*'): p.unlink()
for p in (clean/'bootstrap/cache').glob('*.php'): p.unlink()
(clean/'.env').write_text('APP_NAME=AutoconfigExperiment\nAPP_ENV=testing\nAPP_KEY=base64:'+__import__('base64').b64encode(os.urandom(32)).decode()+'\nAPP_DEBUG=false\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\nMAIL_MAILER=array\nBROADCAST_CONNECTION=null\nSTORYFEED_RECORDING_ENABLED=true\n')
# Isolate dependencies that Composer linked to other working repositories.
for p in list((clean/'vendor').rglob('*')):
 if p.is_symlink() and p.is_dir():
  target=p.resolve(); p.unlink(); subprocess.run(['cp','-cR',str(target),str(p)],check=True)
changes=subprocess.check_output([PHP,str(HERE/'strip.php'),str(clean)],text=True)
(art/'strip-changes.json').write_text(changes)
for rel in ['routes/feed.php','app/Stories','app/Storyfeed','app/Providers/StoryfeedServiceProvider.php','app/Enums/ClientActivity.php','app/Enums/DocumentActivity.php','app/Enums/EngagementActivity.php']:
 p=clean/rel
 if p.is_dir(): shutil.rmtree(p)
 elif p.exists(): p.unlink()
p=clean/'bootstrap/providers.php'; p.write_text('\n'.join(l for l in p.read_text().splitlines() if 'StoryfeedServiceProvider' not in l)+'\n')
# Existing UI/tests still refer to the removed integration. Preserve as evidence,
# but inventory only app source and never use those references as candidates.
(art/'stripped.diff').write_text(subprocess.check_output(['git','-C',str(clean),'diff','--','app','routes/feed.php','bootstrap/providers.php'],text=True))
with (art/'inventory.json').open('w') as out: subprocess.run([PHP,str(HERE/'scan.php'),str(clean)],stdout=out,check=True)
print(json.dumps({'answer_pairs':len(key),'strip_files':len(json.loads(changes)),'inventory_bytes':(art/'inventory.json').stat().st_size}))
