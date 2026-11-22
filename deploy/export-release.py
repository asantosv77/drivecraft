#!/usr/bin/env python3
"""Export only maintained application code and clean-deploy assets, never live data."""
from pathlib import Path
import argparse, datetime, hashlib, io, json, tarfile

DEPLOY = Path(__file__).resolve().parent
ROOT = DEPLOY.parent
EXCLUDE = {'config.php', 'check_ini.php', 'check_albums.php', 'fix.php', 'recover.php', 'test-php-limits.php', 'setup_admin.php'}
DEPLOY_FILES = ['Dockerfile','Dockerfile.dockerignore','docker-compose.yaml','.env.example','config.local.php','apache.conf','php.ini','prepare-image.php','bootstrap.php','entrypoint.sh','worker-loop.php','health.php','Install.ps1','Instalar.cmd','install.sh','schema.json','export-release.py','export-schema.php','repository.gitignore','repository.gitattributes','README.md','TESTS.md']

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')
    output = args.output or DEPLOY/'dist'/('drivecraft-local-'+stamp+'.tar.gz')
    output = output.resolve()
    if output.exists():
        raise SystemExit('The output file already exists; choose a new name.')
    files = {}
    for f in ROOT.iterdir():
        if f.is_file() and not f.is_symlink() and f.name not in EXCLUDE and (f.suffix in {'.php','.js','.css','.svg','.png','.ico'} or f.name=='dc_i18n_en_dcft.json'):
            files[f.name] = f
    for directory in ['vendor','skills']:
        for f in sorted((ROOT/directory).rglob('*')):
            if f.is_file() and not f.is_symlink():
                if f.name.startswith('.env') or any(part in {'backups','secrets','.git'} for part in f.relative_to(ROOT).parts):
                    raise SystemExit('Unexpected private file in code resources: '+str(f.relative_to(ROOT)))
                files[f.relative_to(ROOT).as_posix()] = f
    for name in DEPLOY_FILES:
        f = DEPLOY/name
        if not f.is_file() or f.is_symlink():
            raise SystemExit('Required deployment file missing: '+name)
        files['deploy/'+name] = f
    files['.gitignore'] = DEPLOY/'repository.gitignore'
    files['.gitattributes'] = DEPLOY/'repository.gitattributes'
    files['README.md'] = DEPLOY/'README.md'
    if (ROOT/'LICENSE').is_file():
        files['LICENSE'] = ROOT/'LICENSE'
    tables = json.loads((DEPLOY/'schema.json').read_text(encoding='utf-8'))
    if not tables or any(set(row)!={'name','ddl'} or not row['ddl'].startswith('CREATE TABLE IF NOT EXISTS `') for row in tables):
        raise SystemExit('Schema contains unexpected data; export structure only.')
    manifest = {'created_utc':stamp,'schema_tables':len(tables),'files':{n:hashlib.sha256(f.read_bytes()).hexdigest() for n,f in sorted(files.items())}}
    output.parent.mkdir(parents=True,exist_ok=True)
    with tarfile.open(output,'w:gz') as archive:
        for name,f in sorted(files.items()):
            info=archive.gettarinfo(str(f),arcname='drivecraft/'+name)
            info.uid=info.gid=0;info.uname=info.gname='';info.mode=0o755 if name.endswith('.sh') else 0o644
            with f.open('rb') as stream:archive.addfile(info,stream)
        data=(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n').encode()
        info=tarfile.TarInfo('drivecraft/RELEASE-MANIFEST.json');info.size=len(data);info.mode=0o644
        archive.addfile(info,io.BytesIO(data))
    digest=hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix+'.sha256').write_text(digest+'  '+output.name+'\n',encoding='utf-8')
    print(str(output))
    print(str(len(files))+' code/deployment files; '+str(len(tables))+' empty table definitions. No .env, accounts, sessions, public links or stored files included.')

if __name__=='__main__':
    main()
