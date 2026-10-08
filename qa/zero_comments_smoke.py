#!/usr/bin/env python3
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
errors = []
for base, suffixes in [('js', {'.js'}), ('modules/esm', {'.mjs'}), ('styles', {'.css'})]:
    for path in (ROOT / base).rglob('*'):
        if not path.is_file() or path.suffix not in suffixes:
            continue
        source = path.read_text(encoding='utf-8', errors='replace')
        relative = path.relative_to(ROOT).as_posix()
        for number, line in enumerate(source.splitlines(), 1):
            stripped = line.lstrip()
            if stripped.startswith('//') or stripped.startswith('/*') or stripped.startswith('*/'):
                errors.append(f'{relative}:{number}: comment forbidden')
            if re.search(r'[;})]\s+//\s*[A-Za-zÀ-ÿ]', line):
                errors.append(f'{relative}:{number}: inline comment forbidden')
        if path.suffix == '.css' and '/*' in source:
            errors.append(f'{relative}: CSS comments forbidden')
        if path.suffix in {'.js', '.mjs'} and re.search(r'/\*\s*[A-Za-zÀ-ÿ]', source):
            errors.append(f'{relative}: block comment forbidden')
probe = r'''$root=$argv[1];$errors=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/api',FilesystemIterator::SKIP_DOTS));foreach($it as $f){if($f->getExtension()!=='php')continue;$src=file_get_contents($f->getPathname());foreach(token_get_all($src) as $t){if(is_array($t)&&($t[0]===T_COMMENT||$t[0]===T_DOC_COMMENT)){$errors[]=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1)).':'.$t[2];}}}echo json_encode($errors);'''
result = subprocess.run(['php', '-r', probe, str(ROOT)], check=True, capture_output=True, text=True)
import json
for item in json.loads(result.stdout or '[]'):
    errors.append(f'{item}: PHP comment forbidden')
print('Zero comments policy: ' + ('PASS' if not errors else 'FAIL'))
if not errors:
    print(' frontend=zero backend=zero')
for error in errors[:100]:
    print(' - ' + error)
sys.exit(bool(errors))
