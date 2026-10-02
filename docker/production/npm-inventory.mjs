// Metadata only: preserve the exact production dependency inventory of the bundle.
import { readFileSync, mkdirSync, copyFileSync } from 'node:fs'
const lock = JSON.parse(readFileSync('/build/package-lock.json', 'utf8'))
for (const [path, pkg] of Object.entries(lock.packages)) {
  if (!path || pkg.dev) continue
  if (!path.startsWith('node_modules/') || path.includes('..')) throw new Error('Unexpected dependency path')
  const source = '/build/' + path + '/package.json'
  if (JSON.parse(readFileSync(source, 'utf8')).version !== pkg.version) throw new Error('Inventory version mismatch')
  mkdirSync('/inventory/' + path, { recursive: true })
  copyFileSync(source, '/inventory/' + path + '/package.json')
}
