import { chromium } from '@playwright/test'
import { readFile, unlink } from 'node:fs/promises'
import assert from 'node:assert/strict'
import { verifyWorkflow } from './workflow.mjs'

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
  let createdPath
  const createdTitle = '画面登録検証・架空の相談😀'
  const createdBody = '<script>window.__bodyXss=1</script>\n<img src=x onerror="window.__bodyXss=1">\n架空の内容・次行'
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
    // Every writing scenario is confined to the guarded, isolated test project.
    if (!local && key === 'employee_a') {
      step = 'employee A creation form direct route, reload and discard guard'
      await page.getByRole('link', { name: '依頼を登録', exact: true }).click()
      await page.getByLabel(/^タイトル/).waitFor()
      await page.reload(); await page.getByLabel(/^タイトル/).waitFor()
      await page.getByLabel(/^タイトル/).fill(createdTitle)
      await page.getByRole('link', { name: '一覧へ戻る' }).click()
      assert.match(page.url(), /\/requests\/new$/) // dialog is dismissed, input remains
      assert.equal(await page.getByLabel(/^タイトル/).inputValue(), createdTitle)
      await page.getByLabel(/^種別/).selectOption('improvement')
      await page.getByLabel(/^内容/).fill(createdBody)
      await page.setViewportSize({ width: 390, height: 844 })
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
      step = 'employee A create to returned-ID detail'
      const creation = page.waitForResponse(response => response.url().endsWith('/api/requests') && response.request().method() === 'POST')
      await page.getByRole('button', { name: '登録する', exact: true }).click()
      const response = await creation; assert.equal(response.status(), 201)
      const { data } = await response.json(); createdPath = `/requests/${data.id}`
      await page.waitForURL('**' + createdPath)
      await page.getByRole('heading', { name: createdTitle, exact: true }).waitFor()
      assert.equal(await page.locator('.request-body').textContent(), createdBody)
      assert.equal(await page.locator('.request-body script, .request-body img').count(), 0)
      assert.equal(await page.evaluate(() => window.__bodyXss), undefined)
      assert.equal(await page.locator('textarea').count(), 0)
      assert.equal(data.requester.display_name, '社員A'); assert.equal(data.status, 'open'); assert.equal(data.assignee, null); assert.equal(data.version, 1)
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
      await page.reload(); await page.getByRole('heading', { name: createdTitle, exact: true }).waitFor()
      await page.getByRole('link', { name: '一覧へ戻る' }).click(); await page.waitForSelector('tbody tr')
      // Fixture timestamps may put the new row on either page; locate by returned ID, not text alone.
      let link = page.locator(`a[href="${createdPath}"]`)
      if (!(await link.count())) { await page.getByRole('button', { name: '次へ', exact: true }).click(); await page.waitForSelector('tbody tr'); link = page.locator(`a[href="${createdPath}"]`) }
      assert.equal(await link.textContent(), createdTitle)
      await link.click(); await page.getByRole('heading', { name: createdTitle, exact: true }).waitFor()
    }
    if (!local && key !== 'employee_a') {
      step = key + ' created detail scope and direct reload'
      await page.goto(createdPath); await page.reload()
      if (key === 'employee_b') {
        await page.getByText('対象が見つかりません。', { exact: true }).waitFor()
        assert.equal(await page.locator('.request-body').count(), 0)
      } else {
        await page.getByRole('heading', { name: createdTitle, exact: true }).waitFor()
        assert.equal(await page.locator('.request-body').textContent(), createdBody)
        step = key + ' IT creation UI and server refusal'
        await page.goto('/requests/new'); await page.getByText('この操作は利用できません。', { exact: true }).waitFor()
        assert.equal(await page.locator('textarea').count(), 0)
        const status = await page.evaluate(async () => {
          const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
          const response = await fetch('/api/requests', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }, body: JSON.stringify({ title: '拒否確認', category: 'inquiry', body: '隔離環境専用' }) })
          return response.status
        })
        assert.equal(status, 403)
        await page.goto('/requests'); await page.waitForSelector('tbody tr')
        // Completed data is seeded on the second page of the full IT listing.
        await page.getByRole('button', { name: '次へ', exact: true }).click(); await page.waitForSelector('tbody tr')
        await page.locator('tr').filter({ has: page.locator('.completed') }).locator('.title-cell a').click()
        await page.getByText('完了した依頼です。', { exact: true }).waitFor()
      }
    }
    if (!local) {
      step = key + ' nonexistent detail matches forbidden display'
      await page.goto('/requests/9223372036854775807')
      await page.getByText('対象が見つかりません。', { exact: true }).waitFor()
      assert.equal(await page.locator('.request-body').count(), 0)
      await page.getByRole('link', { name: '一覧へ戻る' }).click(); await page.waitForSelector('tbody tr')
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
  if (!local) await verifyWorkflow(browser, accounts, createdPath, value => { step = value })
  console.log(local ? 'PASS: real Chromium + existing local demo; A/B/IT scopes, login/logout, direct routes, reload, narrow layout.'
    : 'PASS: isolated real Chromium/Laravel/PostgreSQL; existing create/read/auth checks plus candidate paging, assignment/change/clear, 4 transitions/completion, employee 403, independent X/Y stale-version 409 without replay (sequential stale view, not simultaneous send).')
} catch (error) {
  console.error(`FAIL: browser step ${step}; ${error.name}; sensitive details omitted.`)
  process.exitCode = 1
} finally {
  await browser?.close()
  if (!local) {
    try { await unlink(path) } catch (error) { if (error.code !== 'ENOENT') process.exitCode = 1 }
  }
}
