import assert from 'node:assert/strict'

// Isolated DB only; no trace, screenshots, credentials or response bodies in logs.
export async function verifyComments(browser, accounts, step) {
  const contexts = []
  async function login(key) {
    const context = await browser.newContext({ baseURL: 'http://127.0.0.1:5173', locale: 'ja-JP' }); contexts.push(context)
    const page = await context.newPage(); page.setDefaultTimeout(10000)
    await page.goto('/login'); await page.waitForFunction(() => !document.querySelector('form button')?.disabled)
    await page.getByLabel(/メールアドレス/).fill(accounts[key].email)
    await page.getByLabel(/^パスワード/).fill(accounts[key].password)
    await page.getByRole('button', { name: 'ログイン', exact: true }).click(); await page.waitForSelector('tbody tr')
    return { context, page }
  }
  async function call(page, path, method = 'GET', body) {
    return page.evaluate(async ({ path, method, body }) => {
      const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
      const response = await fetch(path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }, body: body === undefined ? undefined : JSON.stringify(body) })
      return { status: response.status, data: await response.json() }
    }, { path, method, body })
  }
  async function open(page, path) {
    await page.goto(path); await page.waitForSelector('.request-body')
    await page.waitForFunction(() => !document.querySelector('textarea')?.disabled && !!document.querySelector('.comment-list'))
  }
  async function post(page, body, status = 201) {
    await page.getByLabel(/コメント本文/).fill(body)
    const pending = page.waitForResponse(response => response.url().endsWith('/comments') && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'コメントを投稿', exact: true }).click()
    const response = await pending; assert.equal(response.status(), status)
    assert.deepEqual(Object.keys(response.request().postDataJSON()), ['body'])
    if (status === 201) await page.getByText('コメントを投稿しました。', { exact: true }).waitFor()
    return response
  }
  try {
    step('comments isolated employee creation and empty comments')
    const a = await login('employee_a'), b = await login('employee_b'), x = await login('it_x')
    const created = await call(a.page, '/api/requests', 'POST', { title: 'コメント画面専用の架空依頼', body: '隔離環境のみ', category: 'inquiry' })
    assert.equal(created.status, 201)
    const path = '/requests/' + created.data.data.id, apiPath = '/api' + path
    await open(a.page, path); await a.page.getByText('コメントはまだありません。', { exact: true }).waitFor()
    const parent = (await call(a.page, apiPath)).data.data
    step('comments employee posting plain text, newline and unchanged parent')
    const markup = '<script>window.commentExecuted=1</script>\n<img src=x onerror="window.commentExecuted=1">\n**文字として表示**'
    await post(a.page, markup)
    assert.equal(await a.page.locator('.comment-body').textContent(), markup)
    assert.equal(await a.page.locator('.comment-body script, .comment-body img').count(), 0)
    assert.equal(await a.page.evaluate(() => window.commentExecuted), undefined)
    assert.equal(await a.page.getByLabel(/コメント本文/).inputValue(), '')
    const after = (await call(a.page, apiPath)).data.data
    assert.equal(after.version, parent.version); assert.equal(after.updated_at, parent.updated_at)

    step('comments separate employee cannot view counts or post')
    await b.page.goto(path); await b.page.getByText('対象が見つかりません。', { exact: true }).waitFor()
    assert.equal(await b.page.locator('.comments').count(), 0)
    for (const method of ['GET', 'POST']) {
      const result = await call(b.page, apiPath + '/comments', method, method === 'POST' ? { body: '拒否される架空本文' } : undefined)
      assert.equal(result.status, 404); assert.equal(result.data.meta, undefined)
    }
    step('comments IT posting and 20 plus pagination; draft remains between pages')
    await open(x.page, path); await post(x.page, 'IT担当者による架空回答')
    // Public API, guarded isolated DB. Add 19 records to reach 21 without touching dev fixtures.
    for (let i = 0; i < 19; i++) assert.equal((await call(a.page, apiPath + '/comments', 'POST', { body: `隔離ページング ${i + 1}` })).status, 201)
    await a.page.getByRole('button', { name: 'コメントを再読込' }).click()
    await a.page.waitForFunction(() => document.querySelectorAll('.comment-list li').length === 20)
    await a.page.getByLabel(/コメント本文/).fill('ページ移動中の下書き')
    await a.page.getByRole('button', { name: 'コメントの次へ' }).click()
    await a.page.waitForFunction(() => document.querySelectorAll('.comment-list li').length === 1)
    assert.equal(await a.page.getByLabel(/コメント本文/).inputValue(), 'ページ移動中の下書き')
    await post(a.page, '末尾に表示する架空コメント')
    await a.page.waitForFunction(() => document.querySelectorAll('.comment-list li').length === 2)
    assert.equal(await a.page.locator('.comment-body').last().textContent(), '末尾に表示する架空コメント')
    step('comments direct reload preserves explicit comment_page and last-page rows')
    assert.equal(new URL(a.page.url()).searchParams.get('comment_page'), '2')
    await a.page.reload(); await a.page.waitForFunction(() => document.querySelectorAll('.comment-list li').length === 2)
    assert.equal(await a.page.locator('.comment-body').last().textContent(), '末尾に表示する架空コメント')
    await a.page.setViewportSize({ width: 390, height: 844 })
    assert(await a.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))

    step('comments ordered stale form: employee drafts before IT completion commits')
    await a.page.getByLabel(/コメント本文/).fill('完了後に拒否される未送信下書き')
    let posts = 0
    a.page.on('request', request => { if (request.url().endsWith(apiPath + '/comments') && request.method() === 'POST') posts++ })
    await x.page.getByRole('radio', { name: /^IT担当者X（/ }).check()
    await x.page.getByRole('button', { name: '担当を変更', exact: true }).click()
    await x.page.getByText('担当者を変更しました。', { exact: true }).waitFor()
    for (const status of ['in_progress', 'waiting_confirmation', 'completed']) {
      await x.page.waitForFunction(() => !document.querySelector('#next-status')?.disabled && !!document.querySelector('#next-status'))
      await x.page.getByLabel('変更先の状態').selectOption(status)
      if (status === 'completed') await x.page.getByRole('checkbox').check()
      const pending = x.page.waitForResponse(response => response.url().endsWith(apiPath + '/status') && response.request().method() === 'PATCH')
      await x.page.getByRole('button', { name: status === 'completed' ? '完了にする' : '状態を変更', exact: true }).click()
      assert.equal((await pending).status(), 200)
    }
    await x.page.getByText('完了した依頼です。', { exact: true }).waitFor()
    // Explicit HTTP completion response above is the ordering point, not a timed sleep.
    const rejected = await post(a.page, '完了後に拒否される未送信下書き', 409)
    assert.equal((await rejected.json()).error.code, 'request_completed')
    await a.page.getByLabel(/未送信のコメント下書き/).waitFor()
    assert.equal(await a.page.getByLabel(/未送信のコメント下書き/).isDisabled(), true)
    assert.equal(await a.page.getByRole('button', { name: 'コメントを投稿', exact: true }).count(), 0)
    await a.page.getByRole('button', { name: '詳細とコメントを確認' }).click()
    await a.page.getByText('末尾に表示する架空コメント', { exact: true }).waitFor()
    const persisted = await call(a.page, apiPath + '/comments?page=2')
    assert.equal(persisted.data.meta.total, 22); assert.equal(posts, 1)
    await a.page.getByRole('button', { name: 'コメント下書きを破棄' }).click()
    await a.page.reload(); await a.page.getByText('完了した依頼です。', { exact: true }).waitFor()
    assert.equal(await a.page.locator('textarea').count(), 0)
    assert.equal(await a.page.evaluate(() => localStorage.length + sessionStorage.length), 0)
  } finally {
    for (const context of contexts) await context.close()
  }
}
