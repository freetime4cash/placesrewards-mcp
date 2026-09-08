import { randomBytes, createHmac, timingSafeEqual } from 'node:crypto';
import { spawn } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { startRevenueServer } from './server.js';
import { loadConfig } from './config.js';

export function launcherEnvironment(env = process.env) {
  if (env.NODE_ENV === 'production') throw new Error('Launch from a non-production environment.');
  const token = randomBytes(32).toString('hex');
  return { token, env: {
    ...env, REVENUE_ENABLED: 'true', REVENUE_ENV: 'sandbox', REVENUE_HOST: '127.0.0.1', REVENUE_LAUNCHER_KEY: token,
    REVENUE_DATA_DIR: env.REVENUE_DATA_DIR || fileURLToPath(new URL('../revenue-engine-data', import.meta.url)),
    REVENUE_AUTH: JSON.stringify([{token, tenantId:'local-workspace',actorId:'local-owner',role:'admin',tier:'enterprise'}]),
  }};
}
function alive(pid) {
  if (!Number.isSafeInteger(pid) || pid < 1) throw new Error('Invalid writer identity. Inspect the store before recovery.');
  try { process.kill(pid,0); return true; } catch (error) { if(error.code==='ESRCH')return false; return true; }
}
async function readSafe(file, maxBytes) {
  const info=await fs.lstat(file);
  if(!info.isFile()||info.isSymbolicLink()||info.size>maxBytes)throw new Error('Unsafe launcher metadata. Inspect the store before recovery.');
  return JSON.parse(await fs.readFile(file,'utf8'));
}
export async function recoverStoppedWriter(directory) {
  const writer=path.join(directory,'writer.lock');
  let owner;
  try { owner=await readSafe(writer,512); } catch(e) {if(e.code==='ENOENT'||e instanceof SyntaxError)return false;throw e;}
  if(alive(owner.pid))return false;
  // Serialize launcher recovery. General API startup never removes a writer lock.
  const guardPath=path.join(directory,'launcher-recovery.lock');
  const guard=await fs.open(guardPath,'wx',0o600).catch(e=>{
    if(e.code==='EEXIST')throw new Error('Another launcher is recovering the workspace. Retry shortly; inspect launcher-recovery.lock if it persists.');
    throw e;
  });
  try {
    await guard.writeFile(JSON.stringify({pid:process.pid}));
    try { owner=await readSafe(writer,512); } catch(e) {if(e.code==='ENOENT'||e instanceof SyntaxError)return false;throw e;}
    if(alive(owner.pid))return false;
    await fs.unlink(writer); return true;
  } finally { await guard.close();await fs.unlink(guardPath); }
}
export async function existingLauncher(directory) {
  let record;
  try {record=await readSafe(path.join(directory,'launcher-session.json'),2048);}catch(e){if(e.code==='ENOENT')return null;throw e;}
  if(record.version!==1||!Number.isInteger(record.port)||record.port<1024||record.port>65535||!/^[a-f0-9]{64}$/.test(record.token||''))throw new Error('Invalid launcher session. Inspect the workspace before restarting.');
  if(!alive(record.pid))return null;
  const challenge=randomBytes(32).toString('hex'),address='http://127.0.0.1:'+record.port;
  try {
    // Prove the listener knows the secret without sending it to a reused port.
    const response=await fetch(address+'/launcher-proof?challenge='+challenge,{redirect:'error',signal:AbortSignal.timeout(2500)});
    const reader=response.body?.getReader();if(!reader)return null;
    let size=0;const chunks=[];
    while(true){const {done,value}=await reader.read();if(done)break;size+=value.length;if(size>4096){await reader.cancel();return null;}chunks.push(value);}
    const data=JSON.parse(Buffer.concat(chunks).toString('utf8'));
    const expected=createHmac('sha256',record.token).update(challenge).digest(),proof=Buffer.from(data.proof||'','hex');
    if(response.ok&&data.ok&&proof.length===expected.length&&timingSafeEqual(proof,expected))return {url:address+'/#key='+record.token,reused:true};
  }catch{/* Unproven listeners never receive credentials. */}
  return null;
}
export async function openBrowser(url) {
  const args=process.platform==='win32'
    ? ['powershell.exe',['-NoProfile','-NonInteractive','-Command',"Start-Process '"+url+"'"]]
    : process.platform==='darwin'?['open',[url]]:['xdg-open',[url]];
  await new Promise((resolve,reject)=>{
    const child=spawn(args[0],args[1],{stdio:'ignore',windowsHide:true});
    child.once('error',reject);child.once('exit',code=>code===0?resolve():reject(new Error('Browser launch failed.')));
  });
}
export async function launch({open=true,env=process.env,browserOpener=openBrowser}={}) {
  const settings=launcherEnvironment(env),config=loadConfig(settings.env);
  try {const info=await fs.lstat(config.directory);if(!info.isDirectory()||info.isSymbolicLink())throw new Error('Use a real, dedicated Revenue Engine data directory.');}catch(e){if(e.code!=='ENOENT')throw e;}
  let reused=await existingLauncher(config.directory);
  if(!reused){
    await recoverStoppedWriter(config.directory);
    let runtime;
    try {runtime=await startRevenueServer(settings.env);}
    catch(error){
      if(error.code!=='STORE_LOCKED')throw error;
      for(let attempt=0;attempt<8&&!reused;attempt++){
        await new Promise(resolve=>setTimeout(resolve,250));reused=await existingLauncher(config.directory);
      }
      if(!reused)throw new Error('A server already owns this workspace, but it was started without the reopen feature. Stop that server once, then run this launcher again.');
    }
    if(runtime){
      const record={version:1,pid:process.pid,port:runtime.server.address().port,token:settings.token};
      const destination=path.join(config.directory,'launcher-session.json'),temp=destination+'.'+randomBytes(8).toString('hex')+'.tmp';
      try {await fs.writeFile(temp,JSON.stringify(record),{flag:'wx',mode:0o600});await fs.rename(temp,destination);}
      catch(e){await runtime.stop();await fs.unlink(temp).catch(()=>{});throw e;}
      reused={...runtime,url:'http://127.0.0.1:'+record.port+'/#key='+record.token,reused:false};
    }
  }
  console.log(reused.reused?'Reopening your running Revenue Engine workspace.':'Revenue Engine is ready. Keep this window open; press Ctrl+C to stop safely.');
  if(open)try{await browserOpener(reused.url);}catch{console.error('The service is running, but your browser could not open. Run the launcher again to retry.');}
  return reused;
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
  launch().then(runtime=>{
    if(runtime.stop)for(const signal of ['SIGINT','SIGTERM'])process.once(signal,()=>runtime.stop().catch(()=>{process.exitCode=1;}));
  }).catch(error=>{console.error('Unable to start Revenue Engine: '+error.message);process.exitCode=1;});
}
