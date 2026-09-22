"""Create ignored local-only values without printing or overwriting credentials."""
from pathlib import Path
import base64
import os


def main():
    root = Path(__file__).resolve().parents[1]
    target = root / '.env'
    if target.exists():
        print('.env already exists; left unchanged.')
        return
    values = {
        'APP_ENV': 'local',
        'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
        'LOCAL_PG_ADMIN_PASSWORD': os.urandom(32).hex(),
        'DEV_DB_PASSWORD': os.urandom(32).hex(),
        'TEST_DB_PASSWORD': os.urandom(32).hex(),
    }
    with target.open('x', encoding='utf-8', newline='\n') as stream:
        stream.write(''.join(f'{key}={value}\n' for key, value in values.items()))
    print('Created ignored .env for local Docker only; values are not displayed.')


if __name__ == '__main__':
    main()
