import assert from 'node:assert/strict'

// Called only by the isolated runner. No trace, screenshots or credential logging.
export async function verifyWorkflow(browser, accounts, path, step) {
  const contexts = []
  const apiPath = '/api' + path
  async function login(key) {
    const context = await browser.newContext({ baseURL: 'http://127.0.0.1:5173', locale: 'ja-JP' })
    contexts.push(context)
    const page = await context.newPage(); page.setDefaultTimeout(10000)
    await page.goto('/login')
    await page.waitForFunction(() => !document.querySelector('form button')?.disabled)
    await page.getByLabel(/メールアドレス/).fill(accounts[key].email)
    await page.getByLabel(/^パスワード/).fill(accounts[key].password)
    await page.getByRole('button', { name: 'ログイン', exact: true }).click()
    await page.waitForSelector('tbody tr')
    return { context, page }
  }
  async function open(page) {
    const response = page.waitForResponse(response => response.url().endsWith(apiPath) && response.request().method() === 'GET')
    await page.goto(path)
    const result = await response; assert.equal(result.status(), 200)
    const { data } = await result.json()
    await page.waitForSelector('.request-body')
    return data
  }
  async function ready(page) {
    await page.waitForFunction(() => {
      const input = document.querySelector('input[name="assignee"]')
      return input && !input.matches(':disabled')
    })
  }
  async function assign(page, name) {
    await ready(page)
    await page.getByRole('radio', { name: new RegExp('^' + name + '（') }).check()
    const response = page.waitForResponse(response => response.url().endsWith(apiPath + '/assignee') && response.request().method() === 'PATCH')
    await page.getByRole('button', { name: '担当を変更', exact: true }).click()
    const result = await response; assert.equal(result.status(), 200)
    const { data } = await result.json(); await ready(page)
    assert.equal(await page.locator('.detail-meta div').filter({ has: page.locator('dt', { hasText: /^担当者$/ }) }).locator('dd').textContent(), name)
    return data
  }
  try {
    step('workflow employee UI hidden and server PATCH/candidate refusal')
    const employee = await login('employee_a')
    let employeeCandidateCalls = 0
    employee.page.on('request', request => { if (request.url().includes('/assignee-candidates')) employeeCandidateCalls++ })
    const initial = await open(employee.page)
    assert.equal(await employee.page.getByRole('heading', { name: '担当・状態の変更' }).count(), 0)
    assert.equal(employeeCandidateCalls, 0)
    const refused = await employee.page.evaluate(async ({ apiPath, version }) => {
      const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
      const statuses = []
      for (const [suffix, body] of [['assignee', { assignee_id: null, expected_version: version }], ['status', { status: 'in_progress', expected_version: version }]]) {
        const response = await fetch(`${apiPath}/${suffix}`, { method: 'PATCH', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }, body: JSON.stringify(body) })
        statuses.push(response.status)
      }
      statuses.push((await fetch(`${apiPath}/assignee-candidates`, { headers: { Accept: 'application/json' } })).status)
      return statuses
    }, { apiPath, version: initial.version })
    assert.deepEqual(refused, [403, 403, 403])
    await employee.context.close()

    step('workflow independent IT X/Y contexts open same version')
    const x = await login('it_x'), y = await login('it_y')
    const xData = await open(x.page), yData = await open(y.page)
    await ready(x.page); await ready(y.page)
    assert.equal(xData.version, yData.version)
    assert.equal(xData.assignee, null)
    assert.equal(await x.page.getByLabel('変更先の状態').isDisabled(), true)
    let yPatches = 0, xCandidates = 0
    y.page.on('request', request => { if (request.method() === 'PATCH') yPatches++ })
    x.page.on('request', request => { if (request.url().includes('/assignee-candidates')) xCandidates++ })
    await y.page.getByRole('radio', { name: /^IT担当者X（/ }).check()

    step('workflow real 20/1 candidates, selection survives page change')
    assert.equal(await x.page.getByRole('radio').count(), 20)
    await x.page.getByRole('radio', { name: /^IT担当者Y（/ }).check()
    await x.page.getByRole('button', { name: '候補の次へ' }).click(); await ready(x.page)
    assert.equal(await x.page.getByRole('radio').count(), 1)
    assert.match(await x.page.locator('.selection').textContent(), /IT担当者Y/)
    await x.page.getByRole('button', { name: '候補の前へ' }).click(); await ready(x.page)
    assert.equal(await x.page.getByRole('radio', { name: /^IT担当者Y（/ }).isChecked(), true)

    step('workflow X assigns Y then stale Y submits old expected_version (not simultaneous)')
    const assigned = await assign(x.page, 'IT担当者Y')
    const staleResponse = y.page.waitForResponse(response => response.url().endsWith(apiPath + '/assignee') && response.request().method() === 'PATCH')
    await y.page.getByRole('button', { name: '担当を変更', exact: true }).click()
    const stale = await staleResponse
    assert.equal(stale.status(), 409); assert.equal((await stale.json()).error.code, 'stale_version')
    assert.equal(stale.request().postDataJSON().expected_version, yData.version)
    await y.page.getByText(/表示していた版は古く/).waitFor(); await ready(y.page)
    assert.equal(yPatches, 1)
    assert.equal(await y.page.getByRole('radio', { checked: true }).count(), 0)
    assert.equal(await y.page.getByRole('button', { name: '担当を変更', exact: true }).isDisabled(), true)
    assert.match(await y.page.locator('.detail-meta').textContent(), /IT担当者Y/)
    assert.equal((await (await y.context.request.get(apiPath)).json()).data.version, assigned.version)

    step('workflow Y explicitly reselects after 409 and uses refreshed version')
    const retryResponse = y.page.waitForResponse(response => response.url().endsWith(apiPath + '/assignee') && response.request().method() === 'PATCH')
    await assign(y.page, 'IT担当者X')
    assert.equal((await retryResponse).request().postDataJSON().expected_version, assigned.version)
    assert.equal(yPatches, 2) // Second request exists only after the explicit choice/click.
    await x.page.getByRole('button', { name: '最新情報を確認' }).click(); await ready(x.page)
    await assign(x.page, 'IT担当者Y')

    step('workflow non-assigned IT X changes assignee and clears in open')
    await assign(x.page, 'IT担当者X')
    const clearResponse = x.page.waitForResponse(response => response.url().endsWith(apiPath + '/assignee') && response.request().method() === 'PATCH')
    await x.page.getByRole('button', { name: '担当を解除' }).click()
    const cleared = await clearResponse; assert.equal(cleared.status(), 200); assert.equal((await cleared.json()).data.assignee, null)
    await ready(x.page); assert.equal(await x.page.getByLabel('変更先の状態').isDisabled(), true)
    await assign(x.page, 'IT担当者Y')

    step('workflow all four allowed transitions and explicit completion')
    for (const status of ['in_progress', 'waiting_confirmation', 'in_progress', 'waiting_confirmation', 'completed']) {
      await ready(x.page)
      await x.page.getByLabel('変更先の状態').selectOption(status)
      if (status === 'completed') {
        assert.equal(await x.page.getByRole('button', { name: '完了にする' }).isDisabled(), true)
        await x.page.getByRole('checkbox').check()
      }
      const beforeCandidates = xCandidates
      const response = x.page.waitForResponse(response => response.url().endsWith(apiPath + '/status') && response.request().method() === 'PATCH')
      await x.page.getByRole('button', { name: status === 'completed' ? '完了にする' : '状態を変更', exact: true }).click()
      const result = await response; assert.equal(result.status(), 200); assert.equal((await result.json()).data.status, status)
      if (status !== 'completed') {
        await ready(x.page); assert.equal(await x.page.getByRole('button', { name: '担当を解除' }).count(), 0)
        await x.page.setViewportSize({ width: 390, height: 844 })
        assert(await x.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
      } else {
        await x.page.getByText('完了した依頼です。', { exact: true }).waitFor()
        assert.equal(await x.page.getByRole('heading', { name: '担当・状態の変更' }).count(), 0)
        assert.equal(xCandidates, beforeCandidates)
        await x.page.reload(); await x.page.getByText('完了した依頼です。', { exact: true }).waitFor()
        assert.equal(xCandidates, beforeCandidates)
      }
    }
    assert.equal(yPatches, 2) // No replay while another context continued updating.
  } finally {
    for (const context of contexts) await context.close()
  }
}
