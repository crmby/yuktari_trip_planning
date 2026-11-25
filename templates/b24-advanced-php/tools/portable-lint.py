#!/usr/bin/env python3
"""Offline syntax only. Never imports app/config, installs dependencies or calls APIs."""
import json
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
counts = {'php': 0, 'js': 0, 'json': 0, 'python': 0}
for p in sorted(root.rglob('*')):
    if not p.is_file() or any(x in p.parts for x in ('.git', 'node_modules', '__pycache__')):
        continue
    if p.name in ('env.php', 'dev.php', 'prod.php') or p.name == '.env':
        continue
    if p.is_symlink():
        raise SystemExit('FAIL: symlink outside reviewed kit: ' + str(p))
    command = None
    key = ''
    if p.suffix == '.php':
        command = ['php', '-n', '-l', str(p)]
        key = 'php'
    elif p.suffix in ('.js', '.mjs', '.cjs'):
        command = ['node', '--check', str(p)]
        key = 'js'
    elif p.suffix == '.json':
        json.loads(p.read_text())
        counts['json'] += 1
    elif p.suffix == '.py':
        compile(p.read_text(), str(p), 'exec')
        counts['python'] += 1
    if command:
        if not shutil.which(command[0]):
            raise SystemExit('FAIL: missing tool ' + command[0])
        result = subprocess.run(command, capture_output=True, text=True)
        if result.returncode:
            sys.stderr.write(result.stdout + result.stderr)
            raise SystemExit(result.returncode)
        counts[key] += 1
print('PASS portable syntax only: ' + json.dumps(counts, sort_keys=True))
