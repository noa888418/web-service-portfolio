"""Read-only business checks using local credentials; never print credentials/Cookies."""
from pathlib import Path
import http.cookiejar
import json
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8000'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def main():
    credentials = json.loads((Path(__file__).resolve().parents[1] / '.local' / 'credentials.json').read_text(encoding='utf-8'))['accounts']
    owners = set()
    step = 'start'
    try:
        for account in ['employee_a', 'employee_b', 'it_x', 'it_y']:
            jar = http.cookiejar.CookieJar()
            client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect())

            def call(method, path, data=None, expected=200):
                nonlocal step
                step = account + ' ' + method + ' ' + path
                headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
                token = next((c.value for c in jar if c.name == 'XSRF-TOKEN'), None)
                if token:
                    headers['X-XSRF-TOKEN'] = urllib.parse.unquote(token)
                req = urllib.request.Request(BASE + path, data=None if data is None else json.dumps(data).encode(), headers=headers, method=method)
                try:
                    response = client.open(req, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    if response.status != expected:
                        raise RuntimeError('unexpected HTTP status')
                    raw = response.read()
                    return json.loads(raw) if raw else None

            step = account + ' authentication'
            call('GET', '/api/me', expected=401)
            call('GET', '/sanctum/csrf-cookie', expected=204)
            call('POST', '/login', credentials[account])
            me = call('GET', '/api/me')['data']
            step = account + ' scoped reads'
            listing = call('GET', '/api/requests')
            if me['role'] == 'employee':
                if not listing['data'] or any(r['requester']['id'] != me['id'] for r in listing['data']):
                    raise RuntimeError('employee scope mismatch')
                owners.add(me['id'])
            elif not owners.issubset({r['requester']['id'] for r in listing['data']}):
                raise RuntimeError('IT scope mismatch')
            for row in listing['data']:
                detail = call('GET', '/api/requests/' + row['id'])['data']
                if detail['id'] != row['id']:
                    raise RuntimeError('detail mismatch')
                call('GET', '/api/requests/' + row['id'] + '/comments')
            step = account + ' logout'
            call('POST', '/logout', {}, expected=204)
            call('GET', '/api/me', expected=401)
        print('Local HTTP: four accounts authenticated, scoped reads succeeded, logout refused subsequent access.')
        return 0
    except Exception as error:
        print('Local HTTP check failed at ' + step + ' (' + type(error).__name__ + '); values are not displayed.')
        return 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, KeyError):
        print('Local credentials unavailable; run setup_demo.py and the guarded seed command first.')
        raise SystemExit(1)
