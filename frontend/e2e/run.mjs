import { chromium } from '@playwright/test'
import { readFile, unlink } from 'node:fs/promises'
import assert from 'node:assert/strict'

// Standalone runner intentionally has no Playwright trace/video/screenshot reporter:
// a fill() failure must not put a password in a report or shared log.
const local = process.env.BROWSER_DEMO === '1'
const path = local ? '/run/local-demo/credentials.json' : '/run/browser/credentials.json'
let step = 'startup'
let browser
try {
  const { accounts } = JSON.parse(await readFile(path, 'utf8'))
  browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({ baseURL: 'http://127.0.0.1:5173', locale: 'ja-JP' })
  const page = await context.newPage()
  page.setDefaultTimeout(10000)
  let dialogs = 0
  page.on('dialog', async dialog => { dialogs++; await dialog.dismiss() })
  const titles = {}
  for (const key of ['employee_a', 'employee_b', 'it_x', 'it_y']) {
    step = key + ' direct /login and reload'
    await page.goto('/login'); await page.reload()
    await page.getByRole('heading', { name: 'ログイン', exact: true }).waitFor()
    await page.getByRole('button', { name: 'ログイン', exact: true }).waitFor({ state: 'visible' })
    await page.waitForFunction(() => !document.querySelector('form button')?.disabled)
    step = key + ' login'
    await page.getByLabel(/メールアドレス/).fill(accounts[key].email)
    await page.getByLabel(/^パスワード/).fill(accounts[key].password)
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.getByRole('heading', { name: '依頼一覧', exact: true }).waitFor()
    await page.waitForSelector('tbody tr')
    step = key + ' scoped list'
    assert.match(page.url(), /\/requests\?page=1$/)
    const userName = { employee_a: '社員A', employee_b: '社員B', it_x: 'IT担当者X', it_y: 'IT担当者Y' }[key]
    const owners = await page.locator('td[data-label="依頼者"]').allTextContents()
    if (key.startsWith('employee')) assert(owners.every(owner => owner === userName))
    titles[key] = await page.locator('.title-cell').allTextContents()
    if (key === 'employee_b') assert(!titles[key].some(title => titles.employee_a.includes(title)))
    if (key.startsWith('it_')) {
      const allOwners = [...owners]
      if (await page.getByRole('button', { name: '次へ', exact: true }).isEnabled()) {
        await page.getByRole('button', { name: '次へ', exact: true }).click()
        await page.waitForSelector('tbody tr')
        allOwners.push(...await page.locator('td[data-label="依頼者"]').allTextContents())
      }
      assert(allOwners.includes('社員A') && allOwners.includes('社員B'))
    }
    step = key + ' direct /requests and reload'
    await page.goto('/requests'); await page.waitForSelector('tbody tr')
    await page.reload(); await page.waitForSelector('tbody tr')
    if (!local && key === 'employee_a') {
      step = 'isolated XSS text and pagination'
      assert.equal(await page.locator('tbody tr').count(), 20)
      assert((await page.locator('.title-cell').allTextContents()).some(title => title.includes('<script>')))
      assert.equal(await page.locator('tbody script, tbody img').count(), 0)
      assert.equal(await page.evaluate(() => window.__xss), undefined)
      assert.equal(dialogs, 0)
      await page.getByRole('button', { name: '次へ', exact: true }).click()
      await page.waitForSelector('tbody tr')
      assert.equal(await page.locator('tbody tr').count(), 1)
      await page.reload(); await page.waitForSelector('tbody tr')
      assert.match(page.url(), /page=2$/)
    }
    step = key + ' narrow layout and logout'
    await page.setViewportSize({ width: 390, height: 844 })
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0)
    await page.getByRole('button', { name: 'ログアウト', exact: true }).click()
    await page.getByText('ログアウトしました。', { exact: true }).waitFor()
    assert.equal(await page.locator('tbody tr').count(), 0)
    const me = await context.request.get('/api/me')
    assert.equal(me.status(), 401)
    await page.goto('/requests'); await page.getByRole('heading', { name: 'ログイン', exact: true }).waitFor()
    await page.setViewportSize({ width: 1280, height: 900 })
  }
  console.log(local ? 'PASS: real Chromium + existing local demo; A/B/IT scopes, login/logout, direct routes, reload, narrow layout.'
    : 'PASS: real Chromium + isolated PostgreSQL/Laravel; A/B/IT scopes, login/logout, direct routes, reload, pagination, XSS text, narrow layout.')
} catch (error) {
  console.error(`FAIL: browser step ${step}; ${error.name}; sensitive details omitted.`)
  process.exitCode = 1
} finally {
  await browser?.close()
  if (!local) {
    try { await unlink(path) } catch (error) { if (error.code !== 'ENOENT') process.exitCode = 1 }
  }
}
