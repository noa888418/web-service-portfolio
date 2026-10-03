"""Credential-free Terraform checks. Never runs a real AWS plan/apply/destroy."""
import argparse
import datetime
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / 'config/terraform.json').read_text(encoding='utf-8'))
TOOLS = ROOT / '.tools' / 'terraform' / CONFIG['terraform_version']
BINARY = TOOLS / ('terraform.exe' if os.name == 'nt' else 'terraform')
LOCAL = ROOT / '.local' / 'terraform-checks'


def run(args, **kwargs):
    return subprocess.run([str(a) for a in args], cwd=ROOT, check=True, **kwargs)


def install():
    system = platform.system().lower()
    if platform.machine().lower() not in ('amd64', 'x86_64'):
        raise RuntimeError('Only reviewed Windows/Linux amd64 downloads are supported.')
    target = system + '_amd64'
    expected = CONFIG['terraform_sha256'][target]
    version = CONFIG['terraform_version']
    url = f'https://releases.hashicorp.com/terraform/{version}/terraform_{version}_{target}.zip'
    data = urllib.request.urlopen(url, timeout=120).read()
    if hashlib.sha256(data).hexdigest() != expected:
        raise RuntimeError('Terraform download checksum mismatch')
    TOOLS.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        BINARY.write_bytes(archive.read(BINARY.name))
    if os.name != 'nt':
        BINARY.chmod(0o755)
    print('Installed verified Terraform', version)


def report_counts(report):
    results = report.get('Results')
    if not isinstance(results, list) or not results:
        raise RuntimeError('IaC scanner returned no results')
    total = sum(r.get('MisconfSummary', {}).get('Successes', 0) +
                r.get('MisconfSummary', {}).get('Failures', 0) for r in results)
    if total <= 0:
        raise RuntimeError('IaC scanner did not evaluate any checks')
    counts = dict.fromkeys(('UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'), 0)
    for result in results:
        reported_failures = result.get('MisconfSummary', {}).get('Failures', 0)
        actual_failures = sum(f.get('Status') == 'FAIL' for f in result.get('Misconfigurations', []))
        if reported_failures != actual_failures:
            raise RuntimeError('IaC failure summary does not match findings')
        for finding in result.get('Misconfigurations', []):
            if finding.get('Status') != 'FAIL':
                continue
            severity = finding.get('Severity')
            if severity not in counts:
                raise RuntimeError('Unexpected IaC severity')
            counts[severity] += 1
    return counts


def ignore_checks():
    ignored = ['infra/bootstrap/terraform.tfstate', 'infra/bootstrap/terraform.tfstate.backup',
               'infra/foundation/review.tfplan', 'infra/foundation/plan.json',
               'infra/foundation/private.tfvars', 'infra/foundation/private.tfvars.json',
               'infra/foundation/.terraform/providers/placeholder',
               'infra/foundation/prod.s3.tfbackend', 'infra/foundation/.terraform.tfstate.lock.info',
               '.local/terraform-state/bootstrap.tfstate', '.env']
    kept = ['infra/bootstrap/.terraform.lock.hcl', 'infra/foundation/.terraform.lock.hcl',
            '.env.example', 'infra/bootstrap/settings.tfvars.example']
    for path in ignored + kept:
        result = subprocess.run(['git', 'check-ignore', '--no-index', '-q', path], cwd=ROOT)
        if result.returncode != (0 if path in ignored else 1):
            raise RuntimeError('Git exclusion contract failed: ' + path)
    tracked = run(['git', 'ls-files', '-z'], capture_output=True).stdout
    result = subprocess.run(['git', 'check-ignore', '--no-index', '--stdin', '-z'],
                            cwd=ROOT, input=tracked, stdout=subprocess.PIPE, check=False)
    if result.returncode not in (0, 1) or result.stdout:
        raise RuntimeError('Tracked ignored files found; inspect paths privately, do not print values')
    print('PASS Git exclusions and tracked-file protection')


def checks():
    if not BINARY.is_file():
        raise RuntimeError('Run python scripts/terraform_checks.py install first')
    ignore_checks()
    LOCAL.mkdir(parents=True, exist_ok=True)
    # New directory: never reads a real backend, tfvars, existing state or plan.
    stage = Path(tempfile.mkdtemp(prefix='run-', dir=LOCAL))
    env = {k: v for k, v in os.environ.items()
           if not k.startswith(('AWS_', 'TF_', 'TFE_', 'TFC_'))}
    cli = stage / 'terraform.rc'
    cli.write_text('disable_checkpoint = true\n', encoding='utf-8')
    empty = stage / 'empty-aws-config'
    empty.write_text('', encoding='utf-8')
    cache = LOCAL / 'provider-cache'
    cache.mkdir(exist_ok=True)
    env.update(TF_PLUGIN_CACHE_DIR=str(cache), TF_CLI_CONFIG_FILE=str(cli), TF_IN_AUTOMATION='true',
               CHECKPOINT_DISABLE='1', AWS_EC2_METADATA_DISABLED='true',
               AWS_SHARED_CREDENTIALS_FILE=str(empty), AWS_CONFIG_FILE=str(empty))
    actual = json.loads(run([BINARY, 'version', '-json'], env=env, capture_output=True).stdout)
    if actual['terraform_version'] != CONFIG['terraform_version']:
        raise RuntimeError('Unexpected Terraform version')
    run([BINARY, 'fmt', '-check', '-recursive', 'infra'], env=env)
    for name in CONFIG['roots']:
        source = ROOT / 'infra' / name
        dest = stage / name
        dest.mkdir()
        for path in source.glob('*.tf'):
            shutil.copy2(path, dest / path.name)
        shutil.copy2(source / '.terraform.lock.hcl', dest / '.terraform.lock.hcl')
        shutil.copytree(source / 'tests', dest / 'tests')
        run([BINARY, f'-chdir={dest}', 'init', '-backend=false', '-input=false', '-lockfile=readonly'], env=env)
        run([BINARY, f'-chdir={dest}', 'validate', '-no-color'], env=env)
        # All committed tests use mock_provider aws; no real-provider tests here.
        tests = list((dest / 'tests').glob('*.tftest.hcl'))
        if not tests or any('mock_provider "aws" {' not in p.read_text(encoding='utf-8') for p in tests):
            raise RuntimeError('Only explicit AWS mock-provider tests are allowed')
        run([BINARY, f'-chdir={dest}', 'test', '-no-color'], env=env)
    uid = str(os.getuid()) if hasattr(os, 'getuid') and os.getuid() else '10001'
    gid = str(os.getgid()) if hasattr(os, 'getgid') and os.getgid() else '10001'
    evidence = {'verified_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'terraform': CONFIG['terraform_version'], 'aws_provider': CONFIG['aws_provider_version'],
                'trivy': CONFIG['trivy_image'], 'aws_connected': False, 'roots': {}}
    failed = False
    for name in CONFIG['roots']:
        # Scanner-only synthetic values. Never real deployment inputs or credentials.
        values = {'expected_account_id': '123456789012'}
        values.update({'state_bucket_name': 'mock-only-portfolio-state'} if name == 'bootstrap'
                      else {'repository_prefix': 'mock-only-portfolio'})
        (stage / name / 'scanner.tfvars').write_text(
            ''.join(f'{key} = {json.dumps(value)}\n' for key, value in values.items()), encoding='utf-8')
        # Read only the reviewed source copy, never repository .local/.env/tfvars.
        run(['docker', 'run', '--rm', '--user', uid + ':' + gid, '--cap-drop=ALL',
             '--security-opt=no-new-privileges:true', '--network=none',
             '--mount', f'type=bind,src={stage / name},dst=/src,readonly',
             '--mount', f'type=bind,src={stage},dst=/reports',
             CONFIG['trivy_image'], 'config', '--skip-check-update', '--skip-version-check',
             '--misconfig-scanners', 'terraform', '--tf-vars', '/src/scanner.tfvars', '--skip-dirs', '.terraform',
             '--include-non-failures', '--format', 'json', '--output', f'/reports/{name}.json',
             '--exit-code', '0', '/src'], env=env)
        data = json.loads((stage / (name + '.json')).read_text(encoding='utf-8'))
        counts = report_counts(data)
        failed |= bool(counts['HIGH'] or counts['CRITICAL'])
        evidence['roots'][name] = {'severity_counts': counts,
                                   'result': 'failed' if counts['HIGH'] or counts['CRITICAL'] else 'passed'}
        print(name, 'IaC findings:', counts)
    evidence['result'] = 'failed' if failed else 'passed'
    (stage / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n', encoding='utf-8')
    print('Reports:', stage.relative_to(ROOT).as_posix())
    if failed:
        raise RuntimeError('IaC HIGH/CRITICAL findings; inspect reports, do not suppress')
    print('PASS two implemented roots; AWS plan/apply/permissions are NOT verified')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['install', 'check', 'ignore-check'])
    args = parser.parse_args()
    try:
        {'install': install, 'check': checks, 'ignore-check': ignore_checks}[args.command]()
    except (OSError, RuntimeError, subprocess.CalledProcessError, ValueError, KeyError) as exc:
        print('Terraform checks failed:', str(exc), file=sys.stderr)
        sys.exit(1)
