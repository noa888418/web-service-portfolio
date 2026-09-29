"""Create private, ignored credentials for this local demo; never print values."""
from pathlib import Path
import csv
import base64
import json
import os
import subprocess


def prepare(root: Path) -> bool:
    directory = root / '.local'
    if directory.is_symlink():
        raise RuntimeError('Refusing a symlink for the credentials directory.')
    directory.mkdir(mode=0o700, exist_ok=True)
    if os.name == 'nt':
        identity = subprocess.run(['whoami', '/user', '/fo', 'csv', '/nh'], capture_output=True, text=True, check=True)
        sid = next(csv.reader(identity.stdout.splitlines()))[1]
        subprocess.run(['icacls', str(directory), '/inheritance:r', '/grant:r',
                        f'*{sid}:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F'],
                       capture_output=True, check=True)
    else:
        directory.chmod(0o700)
    target = directory / 'credentials.json'
    if target.exists() or target.is_symlink():
        return False
    accounts = {key: {'email': email, 'password': base64.urlsafe_b64encode(os.urandom(32)).decode().rstrip('=')} for key, email in {
        'employee_a': 'employee-a@example.test', 'employee_b': 'employee-b@example.test',
        'it_x': 'it-x@example.test', 'it_y': 'it-y@example.test',
    }.items()}
    # Exclusive create also protects against two simultaneous setup processes.
    try:
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        return False
    with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
        json.dump({'accounts': accounts}, stream, indent=2)
        stream.write('\n')
    return True


if __name__ == '__main__':
    try:
        created = prepare(Path(__file__).resolve().parents[1])
        print('Created .local/credentials.json for local use only.' if created else 'Existing credentials file left unchanged.')
    except Exception:
        print('Local credential preparation failed; values are not displayed.')
        raise SystemExit(1)
