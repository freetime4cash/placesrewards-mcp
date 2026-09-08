import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp,mkdir,rm,writeFile,readFile,access } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { launch,existingLauncher,recoverStoppedWriter } from './launch.js';

async function fixture(t) {
  const root=await mkdtemp(path.join(tmpdir(),'revenue-launch-')),directory=path.join(root,'revenue-engine-data');
  const runtimes=[];await mkdir(directory);t.after(async()=>{for(const runtime of runtimes)await runtime.stop();await rm(root,{recursive:true,force:true});});
  const socket=http.createServer();socket.listen(0,'127.0.0.1');await once(socket,'listening');const port=socket.address().port;
  await new Promise(resolve=>socket.close(resolve));
  return {directory,port,keep:runtime=>runtimes.push(runtime),env:{REVENUE_DATA_DIR:directory,REVENUE_PORT:String(port)}};
}
test('double launch reopens the authenticated workspace without a second writer or lost records',async t=>{
  const f=await fixture(t),opened=[];
  const first=await launch({env:f.env,open:true,browserOpener:async url=>opened.push(url)});f.keep(first);
  const token=new URL(first.url).hash.slice(5),base=first.url.split('/#')[0];
  const created=await fetch(base+'/v1/opportunities',{method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json','Idempotency-Key':'launch-test'},body:JSON.stringify({records:[{id:'launch-test',name:'Retained business'}]})});
  assert.equal(created.status,200);
  const second=await launch({env:f.env,open:true,browserOpener:async url=>opened.push(url)});
  assert.equal(second.reused,true);assert.equal(second.url,first.url);assert.equal(second.server,undefined);assert.deepEqual(opened,[first.url,first.url]);
  const page=await (await fetch(base+'/v1/opportunities',{headers:{Authorization:'Bearer '+token}})).json();assert.equal(page.data.total,1);
  assert.equal(await recoverStoppedWriter(f.directory),false);
});
test('concurrent launchers settle on the same authenticated service',async t=>{
  const f=await fixture(t);const results=await Promise.all([launch({env:f.env,open:false}),launch({env:f.env,open:false})]);
  for(const result of results)if(result.stop)f.keep(result);
  assert.equal(results.filter(x=>!x.reused).length,1);assert.equal(results[0].url,results[1].url);
});
test('a confirmed exited writer can recover without changing its saved state',async t=>{
  const f=await fixture(t);
  const child=spawn(process.execPath,['-e','setTimeout(()=>{},20)']);const pid=child.pid;await once(child,'exit');
  await writeFile(path.join(f.directory,'writer.lock'),JSON.stringify({pid,startedAt:new Date().toISOString()}));
  await writeFile(path.join(f.directory,'state.json'),'unchanged-data');
  assert.equal(await recoverStoppedWriter(f.directory),true);
  assert.equal(await readFile(path.join(f.directory,'state.json'),'utf8'),'unchanged-data');
  await assert.rejects(access(path.join(f.directory,'writer.lock')),e=>e.code==='ENOENT');
});
test('a foreign listener never receives the stored launcher credential',async t=>{
  const f=await fixture(t),requests=[],token='a'.repeat(64);
  const server=http.createServer((req,res)=>{requests.push({url:req.url,auth:req.headers.authorization});res.end(JSON.stringify({ok:true,proof:'b'.repeat(64)}));});
  server.listen(f.port,'127.0.0.1');await once(server,'listening');t.after(()=>new Promise(resolve=>server.close(resolve)));
  await writeFile(path.join(f.directory,'launcher-session.json'),JSON.stringify({version:1,pid:process.pid,port:f.port,token}));
  assert.equal(await existingLauncher(f.directory),null);assert.equal(requests.length,1);
  assert.equal(requests[0].auth,undefined);assert.ok(!requests[0].url.includes(token));
});
test('browser failure leaves a usable service and the next launcher can reconnect',async t=>{
  const f=await fixture(t);
  const first=await launch({env:f.env,browserOpener:async()=>{throw new Error('No browser');}});f.keep(first);
  const again=await launch({env:f.env,open:false});assert.equal(again.reused,true);assert.equal(again.url,first.url);
});
