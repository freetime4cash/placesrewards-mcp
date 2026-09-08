import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import http from 'node:http';
import { fixture, baseline, key } from './test-support.js';
import { loadConfig } from './config.js';
import { createRevenueHttpServer } from './http.js';
import { launcherEnvironment } from './launch.js';

async function setup(t) {
  const { app } = await fixture(t);
  const credentials = [
    {token:'a'.repeat(40),tenantId:'tenant-a',actorId:'owner',role:'admin',tier:'enterprise'},
    {token:'b'.repeat(40),tenantId:'tenant-b',actorId:'other',role:'admin',tier:'enterprise'},
    {token:'v'.repeat(40),tenantId:'tenant-a',actorId:'viewer',role:'viewer',tier:'enterprise'},
  ];
  const server = createRevenueHttpServer({app,principals:loadConfig({REVENUE_ENABLED:'true',REVENUE_ENV:'test',REVENUE_AUTH:JSON.stringify(credentials)}).principals,logger:()=>{}});
  server.listen(0,'127.0.0.1');await once(server,'listening');
  t.after(async()=>{await new Promise(resolve=>server.close(resolve));await server.drain();});
  const url='http://127.0.0.1:'+server.address().port;
  const request=(path,body,token=credentials[0].token,requestKey=key())=>fetch(url+path,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json','Idempotency-Key':requestKey},...(body?{body:JSON.stringify(body)}:{})});
  return {url,request,credentials};
}
test('dashboard assets are public but data and session stay authenticated, with strict browser boundaries',async t=>{
  const {url,request}=await setup(t);
  const html=await fetch(url+'/');
  assert.equal(html.status,200);assert.match(html.headers.get('content-security-policy'),/frame-ancestors 'none'/);
  assert.equal(html.headers.get('cache-control'),'no-store');
  assert.match(await html.text(),/Open your workspace/);
  for(const path of ['/dashboard-client.js','/dashboard.css'])assert.equal((await fetch(url+path)).status,200);
  assert.equal((await fetch(url+'/v1/session')).status,401);
  const session=await (await request('/v1/session')).json();
  assert.equal(session.data.role,'admin');assert.equal(session.data.token,undefined);assert.equal(session.data.digest,undefined);
  const spoofedHost = await new Promise((resolve,reject)=>{
    http.get(url+'/v1/session',{headers:{Host:'attacker.example',Authorization:'Bearer '+'a'.repeat(40)}},res=>{res.resume();resolve(res.statusCode);}).on('error',reject);
  });
  assert.equal(spoofedHost,403);
  assert.equal((await fetch(url+'/v1/session',{headers:{Origin:'https://attacker.example',Authorization:'Bearer '+'a'.repeat(40)}})).status,403);
  assert.equal((await fetch(url+'/../config.js')).status,401);
  assert.equal((await request('/v1/unknown')).status,404);
});
test('manual callbacks need no provider, deduplicate writes, enforce tenants and retain explicit approval',async t=>{
  const {request,credentials}=await setup(t);
  const [o]=(await (await request('/v1/opportunities',{records:[baseline]})).json()).data;
  const body={opportunityId:o.id,phone:'+12025550129',summary:'Customer requested a callback',dueAt:new Date().toISOString()},k=key();
  const created=await (await request('/v1/callbacks',body,credentials[0].token,k)).json();
  assert.equal(created.ok,true);const c=created.data;
  assert.equal(c.status,'pending_approval');assert.equal(c.source,'manual');
  assert.equal((await (await request('/v1/callbacks',body,credentials[0].token,k)).json()).data.id,c.id);
  assert.equal((await request('/v1/callbacks',body,credentials[1].token)).status,404);
  assert.equal((await request('/v1/callbacks',body,credentials[2].token)).status,403);
  assert.equal((await request('/v1/callbacks',{...body,phone:'555'})).status,400);
  assert.equal((await request('/v1/callbacks/'+c.id+'/outcome',{version:1,outcome:'reached',notes:'Called'})).status,409);
  const approved=await (await request('/v1/callbacks/'+c.id+'/approve',{version:1,reason:'Reviewed requested callback',expiresAt:new Date(Date.now()+60000).toISOString()})).json();
  assert.equal(approved.data.status,'approved');
  const done=await (await request('/v1/callbacks/'+c.id+'/outcome',{version:approved.data.version,outcome:'do_not_call',notes:'Customer asked us to stop'})).json();
  assert.equal(done.data.status,'completed');assert.equal(done.data.verifiedRecoveredRevenue,null);
  assert.equal((await request('/v1/callbacks',body)).status,409);
});
test('launcher pins a sandbox tenant, generates fresh secrets and rejects a production process',()=>{
  const a=launcherEnvironment({}),b=launcherEnvironment({});
  assert.notEqual(a.token,b.token);assert.match(a.token,/^[a-f0-9]{64}$/);
  const config=loadConfig(a.env);assert.equal(config.host,'127.0.0.1');assert.equal(config.environment,'sandbox');
  assert.equal(config.principals[0].tenantId,'local-workspace');
  assert.throws(()=>launcherEnvironment({NODE_ENV:'production'}));
});
test('prospect search applies before pagination and never includes another tenant',async t=>{
  const {request,credentials}=await setup(t);
  await request('/v1/discover',{records:[baseline,{...baseline,id:'other-business',name:'Other Business'}]});
  await request('/v1/opportunities',{records:[{...baseline,id:'private',name:'Other Private'}]},credentials[1].token);
  const page=await (await request('/v1/opportunities?search=OTHER&limit=1')).json();
  assert.equal(page.data.total,1);assert.equal(page.data.items[0].business.name,'Other Business');assert.equal(page.data.nextOffset,null);
  assert.equal((await request('/v1/opportunities?search='+ 'x'.repeat(201))).status,400);
});
