import { createRequire } from 'node:module'
import { readFileSync, mkdirSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'
const { chromium } = createRequire('/app/package.json')('@playwright/test')
const baseURL = 'https://localhost:8443'
let browser, step = 'trust setup'
const noStore = response => assert.match(response.headers()['cache-control'] ?? '', /private.*no-store|no-store.*private/)
try {
  // Trust this CA only in the ephemeral browser user's NSS store; never ignore TLS errors.
  for (const suffix of ['/.pki/nssdb', '/.local/share/pki/nssdb']) {
    const db = process.env.HOME + suffix; mkdirSync(db, { recursive: true })
    execFileSync('certutil', ['-N', '--empty-password', '-d', 'sql:' + db], { stdio: 'ignore' })
    execFileSync('certutil', ['-A', '-d', 'sql:' + db, '-n', 'Portfolio isolated test CA', '-t', 'C,,', '-i', '/run/production/ca.crt'], { stdio: 'ignore' })
  }
  const { accounts } = JSON.parse(readFileSync('/run/production/credentials.json', 'utf8'))
  browser = await chromium.launch({ headless: true })
  async function context(key) {
    const ctx = await browser.newContext({ baseURL, locale: 'ja-JP', ignoreHTTPSErrors: false })
    const page = await ctx.newPage(); page.setDefaultTimeout(15000)
    await page.goto('/login'); await page.getByLabel(/メールアドレス/).waitFor()
    await page.waitForFunction(() => !document.querySelector('form button')?.disabled)
    await page.getByLabel(/メールアドレス/).fill(accounts[key].email)
    await page.getByLabel(/^パスワード/).fill(accounts[key].password)
    const response = page.waitForResponse(r => r.url().endsWith('/login') && r.request().method() === 'POST')
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    const login = await response; assert.equal(login.status(), 200); noStore(login)
    await page.waitForSelector('tbody tr')
    return { ctx, page }
  }
  async function call(page, path, method = 'GET', body, csrf = true, extra = {}) {
    return page.evaluate(async ({ path, method, body, csrf, extra }) => {
      const token = document.cookie.split('; ').find(v => v.startsWith('XSRF-TOKEN='))
      const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...extra }
      if (csrf && token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token.slice(11))
      const response = await fetch(path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
      const text = await response.text()
      return { status: response.status, type: response.headers.get('content-type'), cache: response.headers.get('cache-control'), text }
    }, { path, method, body, csrf, extra })
  }
  step = 'TLS trusted, API errors remain JSON, private paths and forged proxy headers'
  const unauth = await browser.newContext({ baseURL, ignoreHTTPSErrors: false })
  const initial = await unauth.newPage(); await initial.goto('/login')
  for (const path of ['/api/me', '/api/requests']) {
    const r = await call(initial, path, 'GET', undefined, true, { 'X-Forwarded-For': '192.0.2.1', 'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'untrusted.invalid' })
    assert.equal(r.status, 401); assert.match(r.type, /application\/json/); assert.match(r.cache, /no-store/)
  }
  const unknown = await call(initial, '/api/not-a-route')
  assert.equal(unknown.status, 404); assert.match(unknown.type, /application\/json/); assert.match(unknown.cache, /no-store/)
  for (const path of ['/.env', '/.local/credentials.json', '/index.php', '/other.php', '/backend/composer.json', '/assets/missing.js']) {
    const r = await initial.goto(path)
    // Private files are never returned. Unknown SPA route may be HTML but never source/secret data.
    if (path !== '/backend/composer.json') assert.equal(r.status(), 404)
    else assert(!((await r.text()).includes('laravel/framework')))
  }
  await initial.goto('/login')
  const missing = await call(initial, '/login', 'POST', { email: 'nobody@example.test', password: 'invalid-fixture-only' }, false)
  assert.equal(missing.status, 419); assert.match(missing.type, /application\/json/); assert.match(missing.cache, /no-store/)
  await unauth.close()

  step = 'same request through production PHP-FPM: employee creation and Secure cookies'
  const a = await context('employee_a'), b = await context('employee_b'), x = await context('it_x')
  const cookies = await a.ctx.cookies()
  for (const name of ['it_requests_session', 'XSRF-TOKEN']) {
    const cookie = cookies.find(c => c.name === name)
    assert(cookie); assert.equal(cookie.secure, true); assert.equal(cookie.sameSite, 'Lax'); assert.equal(cookie.path, '/')
    assert.equal(cookie.httpOnly, name === 'it_requests_session')
  }
  assert(!(await a.page.evaluate(() => document.cookie)).includes('it_requests_session='))
  await a.page.goto('/requests/new'); await a.page.reload()
  await a.page.getByLabel(/^タイトル/).fill('本番経路の架空依頼')
  await a.page.getByLabel(/^内容/).fill('隔離HTTPS・FPM検証のみ')
  const created = a.page.waitForResponse(r => r.url().endsWith('/api/requests') && r.request().method() === 'POST')
  await a.page.getByRole('button', { name: '登録する', exact: true }).click()
  const response = await created; assert.equal(response.status(), 201); noStore(response)
  const id = (await response.json()).data.id, path = '/requests/' + id, api = '/api' + path
  await a.page.waitForURL('**' + path); await a.page.waitForSelector('.request-body')
  await a.page.goto(path + '?comment_page=1'); await a.page.reload(); await a.page.getByText('コメントはまだありません。', { exact: true }).waitFor()
  await b.page.goto(path); await b.page.getByText('対象が見つかりません。', { exact: true }).waitFor()
  assert.equal((await call(b.page, api)).status, 404)
  const bList = await call(b.page, '/api/requests'); assert(!JSON.parse(bList.text).data.some(row => row.id === id)); assert.match(bList.cache, /no-store/)
  assert.equal((await call(a.page, api + '/comments', 'POST', { body: 'CSRF拒否' }, false)).status, 419)
  assert.equal((await call(a.page, api + '/comments', 'POST', { body: 'CSRF不一致' }, false, { 'X-XSRF-TOKEN': 'invalid' })).status, 419)

  step = 'assignment and start via IT UI'
  await x.page.goto(path); await x.page.getByRole('radio', { name: /^IT担当者X（/ }).check()
  await x.page.getByRole('button', { name: '担当を変更', exact: true }).click()
  await x.page.getByText('担当者を変更しました。', { exact: true }).waitFor()
  async function change(status) {
    await x.page.waitForFunction(() => !!document.querySelector('#next-status') && !document.querySelector('#next-status').disabled)
    await x.page.getByLabel('変更先の状態').selectOption(status)
    if (status === 'completed') await x.page.getByRole('checkbox').check()
    const pending = x.page.waitForResponse(r => r.url().endsWith(api + '/status') && r.request().method() === 'PATCH')
    await x.page.getByRole('button', { name: status === 'completed' ? '完了にする' : '状態を変更', exact: true }).click()
    const r = await pending; assert.equal(r.status(), 200); noStore(r)
  }
  await change('in_progress')
  step = 'employee and IT comments then waiting and completion'
  for (const [page, text] of [[a.page, '社員の架空の追加情報\n<script>window.productionXss=1</script>'], [x.page, 'IT担当者の架空回答']]) {
    await page.waitForFunction(() => !!document.querySelector('#comment-body') && !document.querySelector('#comment-body').disabled)
    await page.getByLabel(/コメント本文/).fill(text)
    await page.getByRole('button', { name: 'コメントを投稿', exact: true }).click()
    await page.getByText('コメントを投稿しました。', { exact: true }).waitFor()
    assert.equal(await page.evaluate(() => window.productionXss), undefined)
  }
  await change('waiting_confirmation'); await change('completed')
  await a.page.reload(); await a.page.getByText('完了した依頼です。', { exact: true }).waitFor()
  await a.page.waitForFunction(() => document.querySelectorAll('.comment-list li').length === 2)
  assert.equal(await a.page.locator('textarea').count(), 0)
  await a.page.goto(path + '?comment_page=2'); await a.page.reload()
  await a.page.getByText('このページにコメントはありません。', { exact: true }).waitFor()
  const completed = await call(a.page, api + '/comments', 'POST', { body: '追加不可' })
  assert.equal(completed.status, 409); assert.equal(JSON.parse(completed.text).error.code, 'request_completed')
  step = 'logout and old Cookie rejection'
  const beforeLogout = await a.ctx.cookies()
  await a.page.getByRole('button', { name: 'ログアウト', exact: true }).click()
  await a.page.getByText('ログアウトしました。', { exact: true }).waitFor()
  assert.equal((await call(a.page, '/api/me')).status, 401)
  await a.ctx.addCookies(beforeLogout); assert.equal((await call(a.page, '/api/me')).status, 401)
  for (const actor of [a, b, x]) await actor.ctx.close()
  console.log('PASS production HTTPS/FPM: trusted CA, Secure/HttpOnly/SameSite, CSRF, no-store, SPA routes, same-request workflow, A/B isolation, completion and logout.')
} catch (error) {
  console.error(`FAIL production step ${step}; ${error.name}; sensitive details omitted.`); process.exitCode = 1
} finally { await browser?.close() }
