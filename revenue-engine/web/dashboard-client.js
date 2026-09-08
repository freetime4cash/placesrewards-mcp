/* Credentials survive refresh in tab-scoped sessionStorage, never localStorage. */
const $ = id => document.getElementById(id);
let token = '', actor, view = 'overview', selected, offset = 0, stage = '', search = '', busy = false, generation = 0;
let pendingCommand = null;
const stages = ['discovered','diagnosed','quantified','prescribed','demonstrated','closed','recovering','measured'];
const labels = {overview:'Overview',prospects:'Prospects',approvals:'Approvals',callbacks:'Callbacks',reports:'Reports',activity:'Activity',settings:'Setup & help'};
const money = v => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(v || 0);
const human = v => String(v ?? '—').replace(/([a-z])([A-Z])/g,'$1 $2').replaceAll('_',' ').replace(/^./, c=>c.toUpperCase());
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;}
function button(text,fn,cls){const b=el('button',text,cls);b.type='button';b.onclick=()=>run(fn);return b;}
function notice(text,error=false){$('message').textContent=text;$('message').className=error?'error':'';}
async function run(fn){try{await fn();}catch(e){notice(e.message,true);}}
async function api(path,body,key){
 const response=await fetch('/v1'+path,{method:body===undefined?'GET':'POST',headers:{Authorization:'Bearer '+token,...(body===undefined?{}:{'Content-Type':'application/json','Idempotency-Key':key})},...(body===undefined?{}:{body:JSON.stringify(body)}),redirect:'error',cache:'no-store',signal:AbortSignal.timeout(20000)});
 const result=await response.json();
 if(!response.ok||!result.ok){const error=new Error((result.error?.message||'Request failed')+' ['+(result.error?.code||response.status)+']');error.definitive=response.status<500;throw error;}
 return result.data;
}
async function command(path,body){
 const signature=JSON.stringify([path,body]);
 if(pendingCommand&&pendingCommand.signature!==signature)throw new Error('A previous save has an unknown result. Retry that exact form before starting another change. Refresh to inspect saved state.');
 pendingCommand ||= {signature,key:crypto.randomUUID()};
 try{const data=await api(path,body,pendingCommand.key);pendingCommand=null;return data;}
 catch(e){if(e.definitive)pendingCommand=null;else e.message='Connection interrupted. Keep this form open and retry unchanged; the same request key will be reused.';throw e;}
}
function panel(title){const p=el('section',undefined,'panel');p.append(el('h2',title));return p;}
function detail(data){
 if(data===null||typeof data!=='object')return el('span',typeof data==='boolean'?(data?'Yes':'No'):String(data??'Not recorded'));
 const box=el('div',undefined,'readable');
 for(const [key,value] of Object.entries(data)){const row=el('div',undefined,'report-row');row.append(el('strong',Array.isArray(data)?'Item '+(Number(key)+1):human(key)),detail(value));box.append(row);}
 return box;
}
function disclosure(title,data){const d=el('details');d.append(el('summary',title),detail(data));return d;}
function table(headers,rows){const wrap=el('div',undefined,'table-wrap'),t=el('table'),head=el('thead'),tr=el('tr');headers.forEach(h=>tr.append(el('th',h)));head.append(tr);t.append(head);const body=el('tbody');for(const row of rows){const r=el('tr');for(const c of row){const td=el('td');td.append(c instanceof Node?c:document.createTextNode(String(c??'—')));r.append(td);}body.append(r);}t.append(body);wrap.append(t);return wrap;}
function download(name,data){const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=el('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
function field(name,label,type='text',value='',options){return {name,label,type,value,options};}
function form(title,fields,submit,note='',saveLabel='Save'){
 if(pendingCommand)throw new Error('Finish retrying the open form before starting another change.');
 $('dialog-title').textContent=title;$('dialog-note').textContent=note;$('form-error').textContent='';$('save').textContent=saveLabel;
 const host=$('editor-fields');host.replaceChildren();
 for(const f of fields){const l=el('label',f.label);const input=el(f.type==='textarea'?'textarea':f.type==='select'?'select':'input');input.name=f.name;
 if(f.type==='select'){for(const option of f.options){const o=el('option',human(option));o.value=option;input.append(o);}}
 else if(f.type!=='textarea')input.type=f.type;
 if(f.type==='number'){input.min='0';input.step='any';}
 input.value=f.value;input.required=!f.optional;l.append(input);host.append(l);}
 $('editor').onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;$('save').disabled=true;$('form-error').textContent='';try{await submit(Object.fromEntries(new FormData($('editor'))));$('dialog').close();notice('Saved.');await render();}catch(error){$('form-error').textContent=error.message;}finally{busy=false;$('save').disabled=false;}};
 $('dialog').showModal();
}
function dismiss(){if(busy||pendingCommand){$('form-error').textContent='Retry the unchanged request to resolve its result before closing.';return;}$('dialog').close();}
function optional(f){return {...f,optional:true};}
function iso(value){return new Date(value).toISOString();}
function localFuture(){const d=new Date(Date.now()+86400000);return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,16);}
function callbackExpiry(dueAt){const d=new Date(Math.max(Date.now()+86400000,Date.parse(dueAt)+3600000));return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,16);}
const write=()=>['admin','operator'].includes(actor.role);
const approve=()=>['admin','approver'].includes(actor.role);
function metric(label,value,caption){const c=el('div',undefined,'card');c.append(el('p',label,'muted'),el('div',value,'metric'),el('small',caption));return c;}
function savedKey(value){try{if(value===undefined)return sessionStorage.getItem('revenue-access');if(value)sessionStorage.setItem('revenue-access',value);else sessionStorage.removeItem('revenue-access');}catch{/* Memory-only access remains usable when storage is disabled. */}return null;}
async function connect(value){token=value;try{actor=await api('/session');}catch(e){token='';savedKey(null);throw e;}savedKey(value);$('login').hidden=true;$('content').hidden=false;$('identity').textContent=human(actor.role)+' · '+actor.tenantId;await render();}
function lock(){generation++;token='';savedKey(null);actor=null;selected=null;pendingCommand=null;$('content').replaceChildren();$('content').hidden=true;$('login').hidden=false;$('identity').textContent='';$('dialog').close();notice('Workspace locked. Run the launcher to reopen, or enter your access key.');}
async function navigate(next){view=next;selected=null;offset=0;search='';await render();}
async function render(){
 if(!token)return;
 const current=++generation,host=el('div');
 $('title').textContent=selected?'Opportunity':labels[view];
 document.querySelectorAll('[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===view));
 try{
 if(selected)await opportunity(host,selected);
 else if(view==='overview')await overview(host);
 else if(view==='prospects'||view==='reports')await prospects(host);
 else if(view==='approvals')await approvals(host);
 else if(view==='callbacks')await callbacks(host);
 else if(view==='activity')await activity(host);
 else settings(host);
 if(current===generation)$('content').replaceChildren(host);
 }catch(e){if(current===generation){$('content').replaceChildren(el('p','Could not load this view. '+e.message,'error'));throw e;}}
}
async function overview(host){
 if(actor.tier==='growth'){const p=panel('Your workspace is ready');p.append(el('p','Open prospects to add a business and work through its lifecycle. Portfolio reporting requires a Pro or Enterprise account.'),button('View prospects',()=>navigate('prospects'),'primary'));host.append(p);return;}
 const d=await api('/dashboard'),stats=el('div',undefined,'stats');
 stats.append(metric('Opportunities',String(d.total),'Businesses in your pipeline'),metric('Monthly opportunity',money(d.modeledMonthlyOpportunity),'Modeled estimate · may overlap'),metric('Awaiting approval',String(d.pendingApproval),'Outreach and recovery actions'),metric('Verified recovered cash','—','No verified cash claims'));
 host.append(stats);const grid=el('div',undefined,'grid'),p=panel('Your pipeline'),list=el('div',undefined,'pipeline');
 for(const s of stages){const row=el('div',undefined,'stage-row');row.append(el('span',human(s)),el('strong',String(d.stages[s])));list.append(row);}p.append(list);grid.append(p);
 const next=panel('Move your next opportunity forward');next.append(el('p','Add a business, record what you know, and work through diagnosis, planning and review. Every recovery action needs approval.'));
 next.append(button('View prospects',()=>navigate('prospects'),'primary'),el('p',''),button('Review approvals',()=>navigate('approvals')));
 next.append(el('h3','Needs attention'),el('p',d.uncertainExecutions+' unresolved execution(s). Review these before retrying or measuring.'),el('small',d.disclaimer));grid.append(next);host.append(grid);
}
function newBusiness(){
 const metrics=['missed_calls_monthly','call_conversion_rate','uncontacted_leads_monthly','lead_close_rate','dormant_customers','reactivation_rate','repeat_purchase_gap_monthly','review_rating','review_count','response_time_hours','social_inactivity_days','website_load_seconds','website_mobile_score'];
 const flags=['website_has_booking','website_has_contact_form','website_has_loyalty','website_has_referral'];
 const fs=[field('name','Business name'),field('id','Unique business ID (letters, numbers or hyphens)','text','business-'+Date.now()),optional(field('industry','Industry')),optional(field('averageTicket','Average sale ($)','number')),optional(field('observedAt','Evidence observed at','datetime-local')),optional(field('source','Evidence source or reference')),...metrics.map(k=>optional(field(k,human(k)+(k.endsWith('_rate')?' (0–1)':''),'number'))),...flags.map(k=>optional(field(k,human(k),'select','',['','true','false'])))];
 form('Add a business',fs,async v=>{const record={id:v.id,name:v.name,metrics:{}};for(const k of ['industry','source'])if(v[k])record[k]=v[k];if(v.observedAt)record.observedAt=iso(v.observedAt);if(v.averageTicket!=='')record.averageTicket=Number(v.averageTicket);for(const k of metrics)if(v[k]!=='')record.metrics[k]=Number(v[k]);for(const k of flags)if(v[k]!=='')record.metrics[k]=v[k]==='true';const [o]=await command('/opportunities',{records:[record]});selected=o.id;},'Leave unknown figures blank. Rates use 0–1; review ratings use 0–5. Estimates are only as reliable as the evidence you enter.','Add business');
}
async function prospects(host){
 const p=panel(view==='reports'?'Opportunity reports':'Your prospect queue'),bar=el('div',undefined,'toolbar');
 if(write()&&view==='prospects')bar.append(button('Add business',newBusiness,'primary'),button('Import records',importRecords));
 const filter=el('select');filter.setAttribute('aria-label','Filter by stage');for(const s of ['',...stages]){const o=el('option',s?human(s):'All stages');o.value=s;filter.append(o);}filter.value=stage;filter.onchange=()=>run(async()=>{stage=filter.value;offset=0;await render();});bar.append(filter);
 const find=el('input');find.type='search';find.placeholder='Search businesses';find.setAttribute('aria-label','Search businesses');find.value=search;const applySearch=async()=>{search=find.value.trim();offset=0;await render();};find.onkeydown=e=>{if(e.key==='Enter')run(applySearch);};bar.append(find,button('Search',applySearch));
 p.append(bar);const page=await api('/opportunities?limit=50&offset='+offset+(stage?'&stage='+stage:'')+(search?'&search='+encodeURIComponent(search):''));
 if(!page.items.length)p.append(el('p','No opportunities here yet. Add a business or import your authorized records.','empty'));
 else p.append(table(['Business','Stage','Queue','Monthly estimate',''],page.items.map(o=>[o.business.name,el('span',human(o.stage),'pill'),human(o.queue.status),money(o.quantified?.modeledMonthlyLoss),button(view==='reports'?'Open report':'Open',async()=>{selected=o.id;await render();})])));
 const paging=el('div',undefined,'toolbar');paging.append(el('span',page.total+' total · showing '+(page.items.length?offset+1:0)+'–'+(offset+page.items.length),'muted'));
 if(offset)paging.append(button('Previous',async()=>{offset=Math.max(0,offset-50);await render();}));
 if(page.nextOffset!==null&&page.nextOffset!==undefined)paging.append(button('Next',async()=>{offset=page.nextOffset;await render();}));p.append(paging);host.append(p);
}
function importRecords(){
 form('Import authorized records',[field('records','Records JSON','textarea','{"records":[]}')],async v=>{const body=JSON.parse(v.records);await command('/discover',body);},'Paste up to 100 records in the documented evidence format. Imports are atomic; duplicates or invalid records leave the batch unchanged.','Import');
}
function versionForm(o,title,path,fields,build,note='',label='Save'){
 form(title,fields,v=>command(path,{version:o.version,...build(v)}),note,label);
}
async function opportunity(host,id){
 const o=await api('/opportunities/'+encodeURIComponent(id)),base='/opportunities/'+encodeURIComponent(id),p=panel(o.business.name);
 p.append(button('← Back to prospects',()=>navigate('prospects')),el('p',human(o.stage)+' · Version '+o.version+' · '+human(o.queue.status)),el('p','Monthly modeled opportunity: '+money(o.quantified?.modeledMonthlyLoss)));
 const controls=el('div',undefined,'actions'),next={discovered:['diagnose','Diagnose'],diagnosed:['quantify','Quantify'],quantified:['prescribe','Build plan'],prescribed:['demo','Prepare demonstration']}[o.stage];
 if(write()&&next)controls.append(button(next[1],()=>versionForm(o,next[1],base+'/'+next[0],[],()=>({}),'Move this opportunity to the next stage.',next[1]),'primary'));
 if(write()&&o.stage==='demonstrated')controls.append(button('Record deal decision',()=>versionForm(o,'Record deal decision',base+'/close',[field('status','Decision','select','won',['won','lost','deferred']),optional(field('agreedMonthlyFee','Agreed monthly fee ($)','number')),optional(field('recoverySharePercent','Agreed recovery share (%)','number')),optional(field('notes','Notes','textarea'))],v=>({status:v.status,...(v.agreedMonthlyFee!==''?{agreedMonthlyFee:Number(v.agreedMonthlyFee)}:{}),...(v.recoverySharePercent!==''?{recoverySharePercent:Number(v.recoverySharePercent)}:{}),...(v.notes?{notes:v.notes}:{})}),'This records agreement details only. It does not bill anyone.')));
 if(write()&&o.stage==='closed'&&o.close?.status==='won')controls.append(button('Prepare recovery actions',()=>versionForm(o,'Prepare recovery actions',base+'/recover',[],()=>({}),'Creates pending actions. Each must be separately reviewed and approved.')));
 if(write()&&o.close?.status==='deferred')controls.append(button('Reopen deal',()=>versionForm(o,'Reopen deal',base+'/reopen',[],()=>({}))));
 if(write()&&o.stage==='recovering')controls.append(button('Measure results',()=>measureForm(o,base)));
 if(write())controls.append(button('Add manual callback',()=>form('Add manual callback',[field('phone','Phone number including country code','tel'),optional(field('name','Contact name')),field('summary','Why is a callback needed?','textarea'),field('dueAt','Review due','datetime-local',localFuture())],v=>command('/callbacks',{opportunityId:o.id,phone:v.phone,summary:v.summary,dueAt:iso(v.dueAt),...(v.name?{name:v.name}:{})}),'Record a requested callback. It will require separate approval; no call is placed.')));
 if(write()&&o.queue.status!=='done')controls.append(button('Manage follow-up',()=>versionForm(o,'Manage prospect queue',base+'/queue',[field('operation','Action','select','claim',['claim','release','snooze','ready']),optional(field('followUpAt','Follow-up time (for snooze)','datetime-local',localFuture()))],v=>({operation:v.operation,...(v.operation==='snooze'?{followUpAt:iso(v.followUpAt)}:{})}))));
 p.append(controls);host.append(p);
 const report=actor.tier==='growth'?{diagnosis:o.report,quantification:o.quantified,prescription:o.recoveryPlan,demonstration:o.demonstration,close:o.close,measurement:o.measurement,evidence:o.business.signals,disclaimer:'Modeled estimates only. No verified recovered cash.'}:await api(base+'/report'),r=panel('Evidence & results');
 for(const [name,data] of [['Diagnosis',report.diagnosis],['Quantification',report.quantification],['Prescription',report.prescription],['Demonstration',report.demonstration],['Deal',report.close],['Measurement',report.measurement]])if(data)r.append(disclosure(name,data));
 r.append(disclosure('Source evidence',report.evidence),el('p',report.disclaimer,'muted'),button('Download report',()=>download('revenue-report-'+o.business.id+'.json',report)),button('Print this view',()=>window.print()));host.append(r);
 const outreach=panel('Outreach drafts');
 if(write()&&!['lost','deferred'].includes(o.close?.status))outreach.append(button('Draft outreach',()=>versionForm(o,'Draft outreach',base+'/outreach',[field('recipient','Recipient email','email'),field('subject','Subject'),field('body','Message','textarea')],v=>({channel:'email',...v}),'Drafting and sandbox execution send no email. Review the exact recipient and message before approval.')));
 if(!o.outreach.length)outreach.append(el('p','No outreach drafted yet.','muted'));
 for(const a of o.outreach)outreach.append(actionCard(o,a,'outreach'));host.append(outreach);
 const recovery=panel('Recovery actions');if(!o.recovery?.actions.length)recovery.append(el('p','Actions appear after a won deal enters recovery.','muted'));for(const a of o.recovery?.actions||[])recovery.append(actionCard(o,a,'recovery'));host.append(recovery);
}
function actionCard(o,a,kind){
 const p=el('article',undefined,'card'),id=a.leakId||a.id,path='/opportunities/'+encodeURIComponent(o.id)+'/'+kind+'/'+encodeURIComponent(id);
 p.append(el('h3',a.subject||a.title||human(a.category)||id),el('span',human(a.status),'pill'),disclosure('Exact action to review',a));
 if(a.recipient)p.append(el('p','To: '+a.recipient),el('p',a.body));
 if(approve()&&!['simulated','executing','uncertain'].includes(a.status)&&!(kind==='recovery'&&o.stage!=='recovering')){
 p.append(button('Review approval',()=>{versionForm(o,'Review exact action',path+'/approval',[field('decision','Decision','select','approve',['approve','revoke']),field('reason','Review reason','textarea'),field('expiresAt','Approval expires','datetime-local',localFuture())],v=>({decision:v.decision,reason:v.reason,...(v.decision==='approve'?{expiresAt:iso(v.expiresAt)}:{})}),'Approval applies only to the exact action shown. Execution is a separate step.','Save decision');$('editor-fields').prepend(detail(a));}));
 }
 if(write()&&a.status==='approved')p.append(button('Run approved simulation',()=>versionForm(o,'Run approved simulation',path+'/execute',[],()=>({}),'No real message, call or recovery will occur. The server rechecks the exact approval and expiry.','Run simulation')));
 if(approve()&&a.status==='uncertain')p.append(button('Resolve uncertain result',()=>versionForm(o,'Resolve uncertain result',path+'/reconcile',[field('outcome','Confirmed result','select','not_executed',['not_executed','simulated']),field('evidence','Evidence for this decision','textarea')],v=>v,'Do not guess. Confirm non-execution or a simulated result from evidence.')));
 if(write()&&kind==='outreach'&&a.status==='simulated')p.append(button('Record outcome',()=>versionForm(o,'Record outreach outcome',path+'/outcome',[field('outcome','Outcome','select','replied',['replied','meeting_booked','declined','no_response']),field('notes','Notes','textarea'),optional(field('followUpAt','Optional follow-up','datetime-local'))],v=>({outcome:v.outcome,notes:v.notes,...(v.followUpAt?{followUpAt:iso(v.followUpAt)}:{})}),'Records a sandbox outcome; does not send a message.')));
 return p;
}
function measureForm(o,base){
 const signals=o.business.signals||[];
 const fields=[field('observedAt','Updated evidence observed at','datetime-local'),field('source','Evidence source / reference'),...signals.map(s=>field(s.key,human(s.key),typeof s.value==='boolean'?'select':'number',String(s.value),typeof s.value==='boolean'?['true','false']:undefined))];
 versionForm(o,'Measure updated evidence',base+'/measure',fields,v=>{
 const metrics=Object.fromEntries(signals.map(s=>[s.key,typeof s.value==='boolean'?v[s.key]==='true':Number(v[s.key])]));
 return {record:{id:o.business.id,name:o.business.name,...(o.business.averageTicket!==undefined?{averageTicket:o.business.averageTicket}:{}),metrics,observedAt:iso(v.observedAt),source:v.source}};
 },'Enter complete updated evidence. The business and average ticket stay fixed. This finalizes a modeled comparison, not verified cash.');
}
async function approvals(host){
 const p=panel('Review before recovery');p.append(el('p','Inspect the exact action, then record your decision. Approval and execution are separate.'));
 let count=0,next=0;
 do{const page=await api('/opportunities?limit=100&offset='+next);for(const o of page.items)for(const [kind,actions] of [['recovery',o.recovery?.actions||[]],['outreach',o.outreach]])for(const a of actions)if(['draft','pending_approval','approved','uncertain'].includes(a.status)){p.append(el('h3',o.business.name),actionCard(o,a,kind));count++;}next=page.nextOffset;}while(next!==null&&next!==undefined);
 if(!count)p.append(el('p','You are all caught up. New recovery actions and outreach drafts will appear here.','empty'));host.append(p);
}
async function callbacks(host){
 const p=panel('Manual callbacks'),page=await api('/callbacks?limit=50&offset='+offset);p.append(el('p','Vapi and Make are deferred. Imported callback tasks appear here; this workspace never places a call.'));
 for(const c of page.items){const box=el('article',undefined,'card');box.append(el('h3',c.call?.phone||'No callback number'),el('p',human(c.status)+' · Due '+new Date(c.dueAt).toLocaleString()),disclosure('Call details and history',c));const base='/callbacks/'+encodeURIComponent(c.id);
 if(!['completed','cancelled'].includes(c.status)){
 if(approve())box.append(button('Approve callback',()=>{versionForm(c,'Approve manual callback',base+'/approve',[field('reason','Reason','textarea'),field('expiresAt','Expires','datetime-local',callbackExpiry(c.dueAt))],v=>({reason:v.reason,expiresAt:iso(v.expiresAt)}),'Review the number and call details first. This does not dial.');$('editor-fields').prepend(detail({phone:c.call.phone,summary:c.call.summary,dueAt:c.dueAt}));}));
 if(write()){box.append(button('Defer',()=>versionForm(c,'Defer callback',base+'/defer',[field('reason','Reason','textarea'),field('dueAt','Next review','datetime-local',localFuture())],v=>({reason:v.reason,dueAt:iso(v.dueAt)}))),button('Cancel callback',()=>versionForm(c,'Cancel callback',base+'/cancel',[field('reason','Reason','textarea')],v=>v)));
 if(c.status==='approved')box.append(button('Record completed manual call',()=>versionForm(c,'Record manual call result',base+'/outcome',[field('outcome','Result','select','reached',['reached','booked','no_answer','do_not_call']),field('notes','What happened?','textarea'),optional(field('dueAt','Next review if no answer','datetime-local',localFuture()))],v=>({outcome:v.outcome,notes:v.notes,...(v.outcome==='no_answer'?{dueAt:iso(v.dueAt)}:{})}),'Record only a call you actually made. A booking is human-reported, not verified revenue.')));}
 }p.append(box);}
 if(!page.items.length)p.append(el('p','No callbacks yet. You can use prospects and recovery planning without connecting a phone provider.','empty'));
 const nav=el('div',undefined,'toolbar');if(offset)nav.append(button('Previous',async()=>{offset=Math.max(0,offset-50);await render();}));if(offset+page.items.length<page.total)nav.append(button('Next',async()=>{offset+=50;await render();}));p.append(nav);host.append(p);
}
async function activity(host){
 const p=panel('Activity log');if(!approve()){p.append(el('p','An approver or administrator account is required to view the audit log.'));host.append(p);return;}
 const page=await api('/audit?limit=50&offset='+offset);p.append(table(['Time','Event','Actor','Opportunity'],page.items.map(a=>[a.at||a.timestamp,human(a.event||a.operation),a.actorId,a.opportunityId])));
 const nav=el('div',undefined,'toolbar');if(offset)nav.append(button('Previous',async()=>{offset=Math.max(0,offset-50);await render();}));if(offset+page.items.length<page.total)nav.append(button('Next',async()=>{offset+=50;await render();}));p.append(nav,disclosure('Full audit page',page));host.append(p);
}
function settings(host){
 const p=panel('Ready for local work');p.append(el('p','Use the Open Revenue Engine launcher to start the service and open this dashboard. Keep its window open while working; press Ctrl+C there to stop safely.'));
 p.append(el('h3','A simple workflow'),el('p','1. Add a prospect or import authorized evidence. 2. Open it and progress through diagnosis, quantification, planning and demonstration. 3. Record the deal decision. 4. Prepare and separately approve recovery actions. 5. Run simulations and measure updated evidence.'));
 p.append(el('h3','Your data and access'),el('p','Records persist in the dedicated revenue-engine-data folder. Sign-in survives refresh in this tab; Lock clears it. Run the launcher again to reconnect automatically. A private local launcher-session file enables reopening; protect your data folder and do not share its credentials. For separate operator and reviewer accounts, use the environment-based server setup documented in the repository.'));
 p.append(el('h3','Backup and restore'),el('p','Stop the service before copying the entire revenue-engine-data folder to a private backup location. To restore, stop the service and replace that folder with a known-good copy. Never copy it into Places Rewards production.'));
 p.append(el('h3','Deferred connections'),el('p','Vapi and Make are not connected. Outreach and recovery transports are simulated. No email, SMS, call, billing action or production change is performed.'));
 p.append(el('h3','Reports'),el('p','Open a business to download its complete report as JSON or print its current view to PDF. Revenue values remain modeled estimates; no actual recovered cash is claimed.'));
 host.append(p);
}
$('login-form').onsubmit=e=>{e.preventDefault();const value=$('token').value;$('token').value='';run(()=>connect(value));};
$('refresh').onclick=()=>run(render);$('logout').onclick=lock;$('dismiss').onclick=dismiss;$('cancel').onclick=dismiss;
$('dialog').addEventListener('cancel',e=>{e.preventDefault();dismiss();});
document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>run(()=>navigate(b.dataset.view)));
document.querySelector('.brand').onclick=e=>{e.preventDefault();run(()=>navigate('overview'));};
let initial=new URLSearchParams(location.hash.slice(1)).get('key')||savedKey();
history.replaceState(null,'',location.pathname);
if(initial)run(()=>connect(initial));
initial=null;
