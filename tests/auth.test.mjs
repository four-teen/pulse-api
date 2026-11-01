import { test } from 'node:test'
import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { createServer } from 'node:net'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const origin = 'http://127.0.0.1:5173'
const credentials = { passcode: 'test-only-pulse-passcode-7824' }

async function startApi(overrides = {}) {
  const storage = await mkdtemp(join(tmpdir(), 'pulse-api-test-'))
  const listener = createServer()
  await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve))
  const port = listener.address().port
  await new Promise(resolve => listener.close(resolve))
  const processHandle = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], {
    cwd: root,
    env: { ...process.env, APP_ENV: 'development', APP_URL: origin, PRESIDENT_PASSCODE: credentials.passcode, PRESIDENT_PASSCODE_HASH: '', PRESIDENT_NAME: 'Test President', PULSE_PROXY_SECRET: '', PULSE_STORAGE_PATH: storage, SESSION_TTL: '60', SINQ_ACADEMICS_ENABLED: 'false', SINQ_ENABLED: 'false', ORACLE_ENABLED: 'false', PAD_ENABLED: 'false', PULSE_INSTRUCTION_SOURCE: '', PULSE_RESEARCH_SOURCE: '', PULSE_EXTENSION_SOURCE: '', PULSE_PRODUCTION_SOURCE: '', PULSE_INVENTORY_SOURCE: '', PULSE_FINANCE_SOURCE: '', DASHBOARD_PERIOD_KEY: '', ...overrides },
    stdio: ['ignore', 'ignore', 'pipe'],
  })
  let logs = ''
  processHandle.stderr.on('data', data => { logs += data.toString() })
  const base = `http://127.0.0.1:${port}`
  let startupError
  processHandle.on('error', error => { startupError = error })
  for (let attempt = 0; attempt < 60; attempt++) {
    if (startupError) throw startupError
    try { if ((await fetch(`${base}/health`)).ok) break } catch {}
    if (attempt === 59) throw new Error(`API did not start: ${logs}`)
    await delay(50)
  }
  return {
    storage,
    request: (path, options) => fetch(`${base}${path}`, options),
    async close() {
      processHandle.kill()
      if (processHandle.exitCode === null) await new Promise(resolve => processHandle.once('exit', resolve))
      // Only remove this test's uniquely generated temporary directory.
      assert.ok(resolve(storage).startsWith(resolve(tmpdir()) + '\\') || resolve(storage).startsWith(resolve(tmpdir()) + '/'))
      await rm(storage, { recursive: true, force: true })
    },
  }
}

const post = (body, extraHeaders = {}) => ({ method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json', ...extraHeaders }, body: JSON.stringify(body) })

test('unavailable academic API keeps the dashboard usable without stale or invented headcounts', async t => {
  const api = await startApi({ SINQ_ACADEMICS_ENABLED: 'true', SINQ_ACADEMICS_LOCAL_TEST_ENABLED: 'false',
    SINQ_API_BASE_URL: 'http://unapproved.example', SINQ_API_TOKEN: 'test-only-private-token-for-academic-counts' })
  t.after(() => api.close())
  const login = await api.request('/auth/login', post(credentials))
  assert.equal(login.status, 200)
  const cookie = login.headers.get('set-cookie').split(';')[0]
  const response = await api.request('/v1/president/dashboard', { headers: { Cookie: cookie } })
  assert.equal(response.status, 200)
  const data = await response.json()
  assert.equal(data.academicCounts.status, 'unavailable')
  assert.equal(data.academicCounts.term, null)
  assert.equal(data.academicCounts.faculty.total, null)
  assert.equal(data.academicCounts.students.total, null)
  assert.ok(data.campuses.every(campus => campus.faculty === null && campus.students === null))
  assert.equal(data.campuses.length, 7)
  assert.doesNotMatch(JSON.stringify(data), /test-only-private-token|unapproved\.example/)
})

test('presidential authentication lifecycle and request protections', async t => {
  const api = await startApi()
  t.after(() => api.close())
  let cookie

  await t.test('health and anonymous session', async () => {
    assert.equal((await api.request('/health')).status, 200)
    const response = await api.request('/auth/session')
    assert.equal(response.status, 401)
    assert.match(response.headers.get('cache-control'), /no-store/)
    for (const path of ['/v1/president/dashboard', '/v1/campuses', '/v1/integrations']) assert.equal((await api.request(path)).status, 401)
  })
  await t.test('rejects wrong methods, origins, content types, and malformed bodies', async () => {
    assert.equal((await api.request('/auth/login')).status, 405)
    assert.equal((await api.request('/auth/login', post(credentials, { Origin: 'https://untrusted.example' }))).status, 403)
    assert.equal((await api.request('/auth/login', { method: 'POST', body: '{}' })).status, 403)
    assert.equal((await api.request('/auth/login', post(credentials, { 'Content-Type': 'text/plain' }))).status, 415)
    assert.equal((await api.request('/auth/login', { ...post(credentials), body: '{broken' })).status, 400)
    assert.equal((await api.request('/auth/login', post({ passcode: [] }))).status, 400)
    assert.equal((await api.request('/auth/login', post({ passcode: true }))).status, 400)
    assert.equal((await api.request('/auth/login', post({ passcode: '' }))).status, 400)
    assert.equal((await api.request('/auth/login', post({ username: 'president', password: credentials.passcode }))).status, 400)
    assert.equal((await api.request('/auth/login', post({ passcode: 'x'.repeat(5000) }))).status, 413)
  })
  await t.test('incorrect passcodes do not create an authenticated session', async () => {
    const wrongPasscode = await api.request('/auth/login', post({ passcode: 'wrong' }))
    assert.equal(wrongPasscode.status, 401)
    assert.match((await wrongPasscode.json()).message, /passcode is incorrect/)
    assert.equal((await api.request('/auth/session')).status, 401)
  })
  await t.test('a passcode alone issues an HttpOnly, SameSite session and restores it', async () => {
    const response = await api.request('/auth/login', post(credentials))
    assert.equal(response.status, 200)
    assert.equal((await response.json()).user.role, 'president')
    const setCookie = response.headers.get('set-cookie')
    assert.match(setCookie, /HttpOnly/i)
    assert.match(setCookie, /SameSite=Strict/i)
    cookie = setCookie.split(';')[0]
    const session = await api.request('/auth/session', { headers: { Cookie: cookie } })
    assert.equal(session.status, 200)
    assert.deepEqual((await session.json()).user, { name: 'Test President', role: 'president' })
    const dashboard = await api.request('/v1/president/dashboard', { headers: { Cookie: cookie } })
    assert.equal(dashboard.status, 200)
    const payload = await dashboard.json()
    assert.equal(payload.schemaVersion, 1)
    assert.equal(payload.campuses.length, 7)
    assert.equal(payload.dataState, 'awaiting_data')
    assert.equal(payload.campuses[0].students, null)
    assert.equal(payload.sources.length, 3)
    assert.equal((await api.request('/v1/campuses', { headers: { Cookie: cookie } })).status, 200)
    assert.equal((await api.request('/v1/integrations', { headers: { Cookie: cookie } })).status, 200)
    assert.equal((await api.request('/v1/integrations', post({}, { Cookie: cookie }))).status, 405)
    assert.equal((await api.request('/v1/integrations/sinq/sync', post({}, { Cookie: cookie }))).status, 404)
  })
  await t.test('cross-origin sign-out fails, valid sign-out invalidates the session', async () => {
    assert.equal((await api.request('/auth/logout', post({}, { Cookie: cookie, Origin: 'https://untrusted.example' }))).status, 403)
    assert.equal((await api.request('/auth/session', { headers: { Cookie: cookie } })).status, 200)
    const response = await api.request('/auth/logout', post({}, { Cookie: cookie }))
    assert.equal(response.status, 200)
    assert.match(response.headers.get('set-cookie'), /Max-Age=0/)
    assert.equal((await api.request('/auth/session', { headers: { Cookie: cookie } })).status, 401)
    assert.equal((await api.request('/v1/president/dashboard', { headers: { Cookie: cookie } })).status, 401)
  })
  await t.test('five failed logins trigger a temporary account lock', async () => {
    for (let i = 0; i < 5; i++) assert.equal((await api.request('/auth/login', post({ passcode: 'wrong' }))).status, 401)
    const response = await api.request('/auth/login', post(credentials))
    assert.equal(response.status, 429)
    assert.ok(Number(response.headers.get('retry-after')) > 0)
  })
})

test('production cookies and optional proxy authentication', async t => {
  const api = await startApi({ APP_ENV: 'production', PULSE_PROXY_SECRET: 'test-only-proxy-secret' })
  t.after(() => api.close())
  assert.equal((await api.request('/auth/login', post(credentials))).status, 403)
  const response = await api.request('/auth/login', post(credentials, { 'X-Pulse-Proxy-Secret': 'test-only-proxy-secret' }))
  assert.equal(response.status, 200)
  assert.match(response.headers.get('set-cookie'), /; secure/i)
})
