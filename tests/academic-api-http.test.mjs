import { test } from 'node:test'
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createServer } from 'node:net'
import { setTimeout as delay } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'

const root=fileURLToPath(new URL('..',import.meta.url))
test('academic API transport uses authenticated GET, validates JSON, rejects redirects and errors', async t=>{
  for (const scenario of ['valid','rejected','redirect','html','oversized','invalid-json']) await t.test(scenario,async()=>{
    const listener=createServer(); await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve)); const port=listener.address().port
    await new Promise(resolve=>listener.close(resolve))
    const env={...process.env,TEST_SCENARIO:scenario,TEST_SOURCE_TOKEN:'test-only-private-token-for-academic-api'}
    const server=spawn('php',['-S',`127.0.0.1:${port}`,'tests/fixtures/academic-api-router.php'],{cwd:root,env,stdio:'ignore'})
    try {
      for(let attempt=0;attempt<50;attempt++){try{await fetch(`http://127.0.0.1:${port}/ready`);break}catch{};await delay(50)}
      const result=spawnSync('php',['-r','require "src/bootstrap.php";require "src/Records.php";require "src/SynkAcademics.php";echo json_encode(PulseSynkAcademics::read());'],{
        cwd:root,encoding:'utf8',env:{...env,APP_ENV:'development',SINQ_ACADEMICS_ENABLED:'true',SINQ_ACADEMICS_LOCAL_TEST_ENABLED:'true',
          SINQ_ACADEMICS_LOCAL_TEST_BASE_URL:`http://127.0.0.1:${port}`,SINQ_ACADEMICS_LOCAL_TEST_TOKEN:env.TEST_SOURCE_TOKEN}})
      assert.equal(result.status,0,result.stderr); const data=JSON.parse(result.stdout)
      assert.equal(data.status,scenario==='valid'?'connected':'unavailable')
      assert.equal(data.faculty.total,scenario==='valid'?0:null)
      assert.doesNotMatch(result.stdout,/must-not-leak|test-only-private-token/)
    } finally { server.kill(); if(server.exitCode===null) await new Promise(resolve=>server.once('exit',resolve)) }
  })
})
