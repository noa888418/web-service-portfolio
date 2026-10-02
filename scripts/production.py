"""Production images + isolated integration. No development volumes or credentials."""
import argparse
import datetime
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile

ROOT = Path(__file__).resolve().parents[1]
LOCAL = ROOT / '.local' / 'production'
REPORTS = ROOT / '.local' / 'production-reports'
TRIVY = 'aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969'
UID = str(os.getuid()) if hasattr(os, 'getuid') and os.getuid() else '10001'
GID = str(os.getgid()) if hasattr(os, 'getgid') and os.getgid() else '10001'
ENV = dict(os.environ, PRODUCTION_UID=UID, PRODUCTION_GID=GID)

def run(args, capture=False, check=True):
    return subprocess.run(args, cwd=ROOT, env=ENV, check=check, text=True,
                          stdout=subprocess.PIPE if capture else None,
                          stderr=subprocess.PIPE if capture else None)

def compose(*args, **kwargs):
    return run(['docker', 'compose', '-f', 'compose.production-test.yaml', *args], **kwargs)

def build():
    for target in ('php', 'nginx'):
        run(['docker', 'build', '-f', 'docker/production/Dockerfile', '--target', target,
             '-t', f'it-requests-{target}:production', '.'])
    run(['docker', 'build', '-f', 'frontend/Dockerfile.browser', '-t', 'it-requests-browser:local', 'frontend'])
    run(['docker', 'build', '-f', 'docker/verification/Dockerfile', '-t', 'it-requests-production-verifier:local', '.'])

def setup():
    LOCAL.mkdir(parents=True, exist_ok=True)
    run(['docker', 'run', '--rm', '--user', f'{UID}:{GID}', '--cap-drop=ALL',
         '--mount', f'type=bind,src={LOCAL},dst=/run/production',
         'it-requests-production-verifier:local', 'node', '/verification/setup.mjs'])

def renew_tls():
    compose('stop', 'nginx')
    run(['docker', 'run', '--rm', '--user', f'{UID}:{GID}', '--cap-drop=ALL',
         '--mount', f'type=bind,src={LOCAL},dst=/run/production',
         'it-requests-production-verifier:local', 'node', '/verification/setup.mjs', '--renew-tls'])

def prepare():
    compose('up', '-d', '--wait', 'test-db')
    compose('--profile', 'tools', 'run', '--rm', 'prepare')

def up():
    compose('up', '-d', '--wait', 'nginx')

def verify():
    up()
    images = {}
    for service in ('php', 'nginx'):
        cid = compose('ps', '-q', service, capture=True).stdout.strip()
        data = json.loads(run(['docker', 'inspect', cid], capture=True).stdout)[0]
        images[service] = data['Image']
        if not data['HostConfig']['ReadonlyRootfs'] or data['Config']['User'].split(':')[0] in ('', '0', 'root'):
            raise RuntimeError('Runtime user/read-only policy failed')
        if service == 'php' and data['HostConfig']['PortBindings']:
            raise RuntimeError('FPM host port exposure')
        if service == 'nginx' and any(binding['HostIp'] != '127.0.0.1'
            for bindings in data['HostConfig']['PortBindings'].values() for binding in bindings):
            raise RuntimeError('HTTPS listener is not loopback only')
        compose('exec', '-T', service, 'sh', '-c', 'if touch /forbidden-write 2>/dev/null; then exit 1; fi')
    compose('exec', '-T', 'php', 'sh', '-c', 'test ! -f /app/bootstrap/cache/config.php')
    compose('--profile', 'tools', 'run', '--rm', 'browser')
    logs = compose('logs', '--no-color', 'php', 'nginx', capture=True).stdout.encode()
    if any(value in logs for value in runtime_secrets()):
        raise RuntimeError('Runtime secret in logs; output suppressed')
    cid = compose('ps', '-q', 'test-db', capture=True).stdout.strip()
    if json.loads(run(['docker', 'inspect', cid], capture=True).stdout)[0]['HostConfig']['PortBindings']:
        raise RuntimeError('Database host port exposure')
    REPORTS.mkdir(parents=True, exist_ok=True)
    (REPORTS / 'integration-evidence.json').write_text(json.dumps({
        'verified_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'result': 'passed',
        'runtime_images': images, 'entrypoint': 'https://localhost:8443',
        'scope': 'trusted isolated Chromium; production FPM; same-request create/assign/start/comments/complete; A/B isolation; Cookie/CSRF/logout; read-only/non-root/private ports',
        'aws_verified': False,
    }, indent=2)+'\n', encoding='utf-8')
    print('PASS runtime read-only, non-root, no config cache and no runtime secret in logs.')

def runtime_secrets():
    secrets = []
    for name in ('runtime.env', 'database.env'):
        path = LOCAL / name
        if path.exists():
            secrets.extend(line.split('=', 1)[1].encode() for line in path.read_text(encoding='utf-8').splitlines()
                           if 'PASSWORD=' in line or 'APP_KEY=' in line)
    credentials = LOCAL / 'credentials.json'
    if credentials.exists():
        secrets.extend(a['password'].encode() for a in json.loads(credentials.read_text(encoding='utf-8'))['accounts'].values())
    for path in (LOCAL / 'private' / 'ca.key', LOCAL / 'tls' / 'server.key'):
        if path.exists(): secrets.append(path.read_bytes().strip())
    return secrets

def image_policy(archive, metadata):
    secrets = runtime_secrets()
    # Inspect every retained layer, including files removed by later layers. No extraction.
    with tarfile.open(archive) as saved:
        manifest = json.load(saved.extractfile('manifest.json'))[0]
        config = saved.extractfile(manifest['Config']).read()
        if any(value in config for value in secrets): raise RuntimeError('Secret in image configuration')
        for layer in manifest['Layers']:
            with tarfile.open(fileobj=io.BytesIO(saved.extractfile(layer).read())) as content:
                for member in content:
                    if not member.isfile(): continue
                    name = member.name.lstrip('./')
                    if name.startswith(('app/tests/', 'app/.env', 'app/.local/', 'app/vendor/phpunit/')) or name.endswith(('credentials.json', 'http-router.php')):
                        raise RuntimeError('Forbidden image file')
                    body = content.extractfile(member).read()
                    if any(value in body for value in secrets): raise RuntimeError('Runtime secret in image layer')
    metadata['layer_runtime_secret_and_forbidden_file_check'] = 'passed'

def scan():
    REPORTS.mkdir(parents=True, exist_ok=True)
    cache = ROOT / '.local' / 'trivy-cache'
    cache.mkdir(parents=True, exist_ok=True)
    failures = []
    for service in ('php', 'nginx'):
        tag = f'it-requests-{service}:production'
        inspected = json.loads(run(['docker', 'image', 'inspect', tag], capture=True).stdout)[0]
        image_id = inspected['Id']
        metadata = {'image': tag, 'image_id': image_id, 'repo_digests': inspected.get('RepoDigests', []),
                    'scan_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'trivy': TRIVY,
                    'policy': 'HIGH/CRITICAL or scanner error fails; no ignore-unfixed', 'platform': inspected['Os']+'/'+inspected['Architecture']}
        try:
            if inspected['Config']['User'] != '10001:10001': raise RuntimeError('Image user failed')
            run(['docker', 'run', '--rm', '--read-only', '--entrypoint', 'sh', image_id, '-c',
                 'for x in node npm composer phpunit gcc g++ cc make chromium; do if command -v "$x" >/dev/null 2>&1; then exit 1; fi; done'])
            archive = REPORTS / f'{service}.tar'
            run(['docker', 'image', 'save', '-o', str(archive), image_id])
            image_policy(archive, metadata)
            base = ['docker', 'run', '--rm', '--user', f'{UID}:{GID}', '-e', 'TRIVY_CACHE_DIR=/cache',
                    '--mount', f'type=bind,src={cache},dst=/cache',
                    '--mount', f'type=bind,src={REPORTS},dst=/reports', TRIVY, '--quiet']
            sbom = f'{service}-sbom.cdx.json'
            run(base + ['image', '--input', f'/reports/{service}.tar', '--format', 'cyclonedx', '--output', '/reports/'+sbom])
            inventory = json.loads((REPORTS / sbom).read_text(encoding='utf-8'))
            names = {component.get('name') for component in inventory.get('components', [])}
            required = {'nginx', 'react', 'react-dom', 'scheduler'} if service == 'nginx' else {'laravel/framework', 'laravel/sanctum', 'libpq'}
            if not required.issubset(names): raise RuntimeError('SBOM missing runtime dependency inventory')
            metadata['inventory_components'] = len(inventory.get('components', []))
            metadata['sbom'] = sbom
            metadata['sbom_sha256'] = hashlib.sha256((REPORTS / sbom).read_bytes()).hexdigest()
            # Full vulnerability report retains all severities; gate below also handles errors.
            report = f'{service}-vulnerabilities.json'
            result = run(base + ['image', '--input', f'/reports/{service}.tar', '--scanners', 'vuln',
                                '--pkg-types', 'os,library', '--format', 'json', '--output', '/reports/'+report,
                                '--exit-code', '0'], check=False)
            if result.returncode: raise RuntimeError('Vulnerability scan unavailable')
            data = json.loads((REPORTS / report).read_text(encoding='utf-8'))
            types = {target.get('Type') for target in data.get('Results', [])}
            expected = 'composer-vendor' if service == 'php' else 'node-pkg'
            if 'alpine' not in types or expected not in types:
                raise RuntimeError('Expected OS/application inventory not scanned')
            metadata['scanned_types'] = sorted(types)
            counts = {level: 0 for level in ('UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL')}
            for target in data.get('Results', []):
                for vuln in target.get('Vulnerabilities', []): counts[vuln['Severity']] += 1
            metadata['severity_counts'] = counts
            db_metadata = cache / 'db' / 'metadata.json'
            if db_metadata.exists(): metadata['vulnerability_database'] = json.loads(db_metadata.read_text(encoding='utf-8'))
            metadata['vulnerability_report'] = report
            metadata['result'] = 'failed' if counts['HIGH'] or counts['CRITICAL'] else 'passed'
            if metadata['result'] == 'failed': failures.append(service)
            print(f'{service}: {metadata["result"]}; severity counts {counts}')
        except (RuntimeError, subprocess.CalledProcessError, OSError, ValueError, KeyError):
            metadata['result'] = 'inspection_error'; failures.append(service)
            print(f'{service}: inspection failed; no success assumed')
        (REPORTS / f'{service}-evidence.json').write_text(json.dumps(metadata, indent=2)+'\n', encoding='utf-8')
    if failures: raise RuntimeError('Image gate failed: '+', '.join(failures))

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['build', 'setup', 'prepare', 'up', 'test', 'scan', 'stop', 'renew-tls'])
    action = parser.parse_args().action
    try:
        {'build': build, 'setup': setup, 'prepare': prepare, 'up': up, 'test': verify,
         'scan': scan, 'stop': lambda: compose('stop'), 'renew-tls': renew_tls}[action]()
    except (RuntimeError, subprocess.CalledProcessError, OSError, ValueError, KeyError):
        print(f'Production {action} failed; stop and inspect non-sensitive results.', file=sys.stderr)
        sys.exit(1)
