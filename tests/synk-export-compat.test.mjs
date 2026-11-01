import { test } from 'node:test'
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createServer } from 'node:net'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const php = process.env.SYNK_EXPORT_TEST_PHP || 'php'
const token = 'isolated-synk-export-test-token-1234567890'

test('source helper deduplicates current-term counts on the selected PHP runtime', () => {
  const term = { academicYearId: 2, academicYear: '2026-2027', semester: 1 }
  const campuses = ['ACCESS', 'Isulan', 'Tacurong', 'Kalamansig', 'Lutayan', 'Palimbang', 'Bagumbayan']
    .map((name, index) => ({ campus_id: index + 1, campus_name: `${name} Campus` }))
  const colleges = [1, 2].map(id => ({ college_id: id, college_name: `College ${id}`, college_code: `C${id}`, campus_id: id }))
  const programs = [1, 2].map(id => ({ program_id: id, program_name: `Program ${id}`, program_code: `P${id}`, college_id: id }))
  const facultyRow = (id, college) => ({ faculty_id: id, college_id: college, ay_id: 2, semester: 1, roster_status: 'active' })
  const faculty = [facultyRow(1, 1), facultyRow(1, 1), facultyRow(1, 2), facultyRow(2, 2), { ...facultyRow(3, 1), semester: 2 }]
  const studentRow = (id, program, firstName) => ({ student_id: id, program_id: program, college_id: program, campus_id: program,
    last_name: 'Peña', first_name: firstName, middle_name: '', suffix_name: '', ay_id: 2, semester: 1, is_active: 1, source_kind: 'class_list' })
  const students = [studentRow(1, 1, 'Ana'), studentRow(1, 2, 'Ana'), studentRow(2, 2, '  ANA '), studentRow(3, 2, 'Jose'),
    { ...studentRow(4, 1, 'Other'), is_active: 0 }, { ...studentRow(5, 1, 'Other'), semester: 2 }]
  const code = 'require "integrations/synk/backend/pulse_academic_counts.php"; $args=json_decode(stream_get_contents(STDIN),true); echo json_encode(SynkAcademicCounts::aggregate(...$args));'
  const result = spawnSync(php, ['-n', '-r', code], { cwd: root, encoding: 'utf8', input: JSON.stringify([term, campuses, colleges, programs, faculty, students, 'active_class_lists']) })
  assert.equal(result.status, 0, result.stderr + result.stdout)
  const data = JSON.parse(result.stdout)
  assert.equal(data.faculty.total, 2)
  assert.equal(data.students.total, 2)
  assert.equal(data.faculty.byCampus.access, 1)
  assert.equal(data.faculty.byCampus.isulan, 2)
  assert.equal(data.students.byCampus.access, 1)
  assert.equal(data.students.byCampus.isulan, 2)
  assert.deepEqual(data.quality, { facultyWithMultipleColleges: 1, studentNamesWithMultipleIds: 1 })
  assert.doesNotMatch(result.stdout, /Peña|ANA|Jose/)
})

test('source export parses and authenticates on the selected PHP runtime', async t => {
  for (const file of ['pulse_api.php', 'pulse_academic_counts.php']) {
    const lint = spawnSync(php, ['-n', '-l', `integrations/synk/backend/${file}`], { cwd: root, encoding: 'utf8' })
    assert.equal(lint.status, 0, lint.stdout + lint.stderr)
  }
  for (const configuredToken of ['', 'REPLACE_WITH_A_PRIVATE_RANDOM_TOKEN', token]) {
    await t.test(configuredToken === token ? 'configured' : configuredToken ? 'placeholder' : 'missing', async () => {
      const listener = createServer()
      await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve))
      const port = listener.address().port
      await new Promise(resolve => listener.close(resolve))
      const env = { ...process.env, SYNK_PULSE_API_TOKEN: configuredToken }
      const server = spawn(php, ['-n', '-S', `127.0.0.1:${port}`, 'tests/fixtures/synk-export-router.php'], { cwd: root, env, stdio: 'ignore' })
      const url = `http://127.0.0.1:${port}/backend/pulse_api.php?resource=academic-counts`
      try {
        let ready = false
        for (let i = 0; i < 50; i++) {
          try { await fetch(url); ready = true; break } catch { await delay(50) }
        }
        assert.ok(ready, 'Source fixture started')
        const response = await fetch(url)
        assert.equal(response.status, configuredToken === token ? 401 : 503)
        assert.equal(response.headers.get('x-pulse-export-version'), 'academic-php73-v1')
        const post = await fetch(url, { method: 'POST' })
        assert.equal(post.status, 405)
        assert.equal(post.headers.get('allow'), 'GET')
        if (configuredToken === token) {
          const headers = { Authorization: `Bearer ${token}` }
          const unknown = await fetch(url.replace('academic-counts', 'unknown'), { headers })
          assert.equal(unknown.status, 404)
          for (const resource of ['academic-counts', 'faculty-profiles']) {
            const authorized = await fetch(url.replace('academic-counts', resource), { headers })
            assert.equal(authorized.status, 500)
            const body = await authorized.text()
            assert.match(body, /temporarily unavailable/)
            assert.doesNotMatch(body, /Parse error|Fatal error|Test source connection|isolated-synk|\.php/)
          }
        }
      } finally {
        server.kill()
        if (server.exitCode === null) await new Promise(resolve => server.once('exit', resolve))
      }
    })
  }
})
