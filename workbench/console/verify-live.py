#!/usr/bin/env python3
"""Two real processes: watch the demo feed, rehash it, resize its PTY, stop it.
Usage: python3 workbench/console/verify-live.py /absolute/path/to/php
No fixtures and no production database. Writes only build/w93-* evidence.
"""
import fcntl
import os
import pty
import re
import select
import struct
import subprocess
import sys
import termios
import time
from pathlib import Path

root = Path(__file__).resolve().parents[2]
php = sys.argv[1] if len(sys.argv) > 1 else 'php'
master, slave = pty.openpty()
fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 40, 100, 0, 0))
env = dict(os.environ, TERM='xterm-256color', COLORTERM='truecolor')
env.pop('NO_COLOR', None)
env.pop('COLUMNS', None)
env.pop('LINES', None)
command = [php, 'workbench/console/artisan']
watch = subprocess.Popen(command + ['storyfeed:console', '--polls=4', '--interval=1', '--limit=3'], cwd=root, env=env, stdin=slave, stdout=slave, stderr=slave)
os.close(slave)
raw = b''
rehash = None
resized = False
deadline = time.monotonic() + 20
while time.monotonic() < deadline:
    ready, _, _ = select.select([master], [], [], .2)
    if ready:
        try:
            data = os.read(master, 65536)
        except OSError:
            break
        if not data:
            break
        raw += data
        if b'frame 1' in raw and rehash is None:
            rehash = subprocess.run(command + ['storyfeed:curate', '--rehash'], cwd=root, env=env, capture_output=True)
            print('curate exit:', rehash.returncode)
            print(rehash.stdout.decode(), end='')
        if b'frame 2' in raw and not resized:
            fcntl.ioctl(master, termios.TIOCSWINSZ, struct.pack('HHHH', 40, 36, 0, 0))
            resized = True
if watch.poll() is None:
    watch.terminate()
watch.wait(timeout=5)
os.close(master)
(root / 'build/w93-live.ansi').write_bytes(raw)
plain = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', raw.decode()).replace('\r', '').replace('\u00a0', ' ')
(root / 'build/w93-live.txt').write_text(plain)
print('watch exit:', watch.returncode)
print(plain)
assert rehash and rehash.returncode == 0 and watch.returncode == 0
assert 'RESYNC: history rewritten' in plain
assert '100 cols' in plain and '36 cols' in plain
assert b'\x1b[?1049h' in raw and b'\x1b[?1049l' in raw
print('PASS: real rehash triggered resync; 100 -> 36 columns; alternate screen restored.')
