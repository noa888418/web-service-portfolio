import { randomBytes } from 'node:crypto'
import { mkdirSync, existsSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
const root = '/run/production'
const renew = process.argv.includes('--renew-tls')
const write = (path, data) => writeFileSync(root + '/' + path, data, { mode: 0o600,
  flag: renew && ['private/server.ext', 'tls/listener.conf'].includes(path) ? 'w' : 'wx' })
const openssl = args => execFileSync('openssl', args, { cwd: root, stdio: 'ignore' })
try {
  if (renew !== existsSync(root + '/runtime.env')) throw new Error('unexpected setup state')
  mkdirSync(root + '/tls', { recursive: true }); mkdirSync(root + '/private', { recursive: true })
  if (!renew) {
  const db = randomBytes(32).toString('hex')
  write('runtime.env', `APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:${randomBytes(32).toString('base64')}\nDB_CONNECTION=pgsql\nDB_HOST=test-db\nDB_PORT=5432\nDB_DATABASE=portfolio_test\nDB_USERNAME=portfolio_test\nDB_PASSWORD=${db}\n`)
  write('database.env', `POSTGRES_DB=portfolio_test\nPOSTGRES_PASSWORD=${randomBytes(32).toString('hex')}\nAPP_DB_USER=portfolio_test\nAPP_DB_PASSWORD=${db}\n`)
  write('schema', '')
  }
  openssl(['req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-days', '7', '-subj', '/CN=Portfolio isolated test CA', '-keyout', 'private/ca.key', '-out', 'tls/ca.crt', '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'])
  openssl(['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=localhost', '-keyout', 'tls/server.key', '-out', 'private/server.csr'])
  write('private/server.ext', 'subjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n')
  openssl(['x509', '-req', '-in', 'private/server.csr', '-CA', 'tls/ca.crt', '-CAkey', 'private/ca.key', '-CAcreateserial', '-out', 'tls/server.crt', '-days', '7', '-extfile', 'private/server.ext'])
  execFileSync('chmod', ['0600', root + '/tls/server.key', root + '/private/ca.key'])
  write('tls/listener.conf', 'listen 8443 ssl;\nssl_certificate /run/tls/server.crt;\nssl_certificate_key /run/tls/server.key;\nssl_protocols TLSv1.2 TLSv1.3;\n')
  execFileSync('chmod', ['0644', root + '/tls/listener.conf'])
  console.log(renew ? 'Renewed seven-day TLS files only; credentials and DB retained.' : 'Created isolated runtime values and seven-day CA; values omitted.')
} catch { console.error('Isolated setup refused or failed; values omitted. Do not overwrite existing credentials.'); process.exitCode = 1 }
