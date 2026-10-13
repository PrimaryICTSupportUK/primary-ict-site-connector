#!/usr/bin/env python3
import re
import zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
slug='primary-ict-site-connector'
text=(root/(slug+'.php')).read_text()
version=re.search(r'^ \* Version: (\d+\.\d+\.\d+)$',text,re.M).group(1)
assert "VERSION = '"+version+"'" in text
assert "VERSION = '"+version+"'" in (root/'jobs.php').read_text()
assert 'Stable tag: '+version in (root/'readme.txt').read_text()
files=[root/p for p in ['primary-ict-site-connector.php','inventory.php','updates.php','github.php','components.php','native-source.php','translation-upgrader.php','jobs.php','uninstall.php','readme.txt']]
files+=sorted(p for folder in ['includes','assets'] for p in (root/folder).rglob('*') if p.is_file())
out=root/'dist';out.mkdir(exist_ok=True)
archive=out/(slug+'-'+version+'.zip')
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
    for p in sorted(files):
        entry=zipfile.ZipInfo(slug+'/'+str(p.relative_to(root)),(2026,1,1,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16;z.writestr(entry,p.read_bytes())
print(archive)
