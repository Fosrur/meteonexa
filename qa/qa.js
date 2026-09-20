'use strict';
(() => {
  const q=s=>document.querySelector(s); const qa=q('#qa-app'); const list=q('#qa-results-list'); const loader=q('#qa-loader');
  const state={authenticated:false,busy:false};
  const text=(key,params={})=>window.MeteoNexaI18n?.tr?.(key,params)||window.meteonexaText?.(key,params)||(/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/i.test(String(key||''))?'':String(key||''));
  const headers=()=>({Accept:'application/json','X-Requested-With':'MeteoNexaQA',...(window.MeteoNexaSecurity?.headers?.()||{}),'X-MeteoNexa-Language':document.documentElement.lang||'it'});
  const safe=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const redirectError=status=>location.replace(`../api/public-error.php?code=${[401,403,404,429,500,502,503,504].includes(Number(status))?Number(status):500}`);
  async function jsonFetch(url,options={}){
    const response=await fetch(url,{cache:'no-store',credentials:'same-origin',redirect:'follow',...options,headers:{...headers(),...(options.headers||{})}});
    const ct=String(response.headers.get('content-type')||'').toLowerCase(); const raw=(await response.text()).replace(/^\uFEFF/,'').trim(); let data={};
    if(raw){if(!ct.includes('application/json')&&!raw.startsWith('{')&&!raw.startsWith('[')){const e=new Error(text('qa.error.non_json'));e.status=response.status||502;e.data={status:response.status,contentType:ct||'unknown'};throw e}try{data=JSON.parse(raw)}catch{const e=new Error(text('qa.error.invalid_json'));e.status=response.status||502;e.data={status:response.status,contentType:ct||'unknown'};throw e}}
    if(!response.ok||data?.ok===false){const e=new Error(String(data?.message?text(data.message):(data?.code||`HTTP ${response.status}`)));e.status=response.status;e.code=data?.code||'';e.data=data;throw e}return data;
  }
  const setBusy=(button,active)=>{state.busy=active;loader?.classList.toggle('active',active);loader?.setAttribute('aria-hidden',String(!active));document.querySelectorAll('[data-qa-action]').forEach(n=>{n.disabled=active;n.classList.toggle('busy',active&&n===button);n.setAttribute('aria-busy',active&&n===button?'true':'false')})};
  function addResult(title,detail='',status='ok',data=null){q('.qa-empty')?.remove();const a=document.createElement('article');a.className=`qa-result ${status}`;const icon=status==='ok'?'✓':status==='warn'?'!':'×';let extra='';if(data!==null){try{extra=`<pre>${safe(JSON.stringify(data,null,2))}</pre>`}catch{}}a.innerHTML=`<span class="qa-result-icon" aria-hidden="true">${icon}</span><div><strong>${safe(title)}</strong>${detail?`<p>${safe(detail)}</p>`:''}${extra}</div>`;list?.prepend(a)}
  const addCheck=row=>addResult(text(row.labelKey||'diag.result.generic.title',row.params||{}),row.detailKey?text(row.detailKey,row.params||{}):'',row.ok===true?'ok':row.ok===false?'bad':'warn',row.meta||null);
  const post=action=>jsonFetch('../api/diagnostics/check.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action})});
  const ai=()=>jsonFetch('../api/ai/chat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text('diag.ai.prompt'),messages:[],context:{generatedAt:new Date().toISOString()},language:document.documentElement.lang||'it'})});
  const trust=()=>jsonFetch('../api/diagnostics/trust-scoreboard.php?deviceId='+encodeURIComponent(window.MeteoNexaSecurity?.deviceId||''));
  const push=()=>jsonFetch('../api/push/test.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({deviceId:window.MeteoNexaSecurity?.deviceId||''})});
  async function execute(name){if(name==='ai')return ai();if(name==='push')return push();if(name==='trust')return trust();return post(name)}
  async function runOne(name){const data=await execute(name);if(name==='summary')(data.checks||[]).forEach(addCheck);else if(name==='weather')addResult(text('qa.result.weather.ok'),text('diag.result.weather.test.ok',{elapsed:data.elapsedMs||0}),'ok',{source:data.source||'Open-Meteo'});else if(name==='engine')addResult(text('qa.result.engine.ok'),'','ok',data.event||data.analysis||data);else if(name==='quality')addResult(text('qa.result.quality.ok'),'','ok',data.checks||data);else if(name==='calibration')addResult(text('qa.result.calibration.ok'),'','ok',data);else if(name==='trust')addResult(text('qa.result.trust.ok'),text(data.scoreboard?.learning?'qa.result.trust.learning':'qa.result.trust.ready'),'ok',data.scoreboard||data);else if(name==='ai')addResult(text('qa.result.ai.ok'),'','ok',{provider:data.provider,model:data.model,answer:data.answer});else if(name==='smtp')addResult(text('qa.result.smtp.ok'),text(data.transport==='smtp'?'diag.result.smtp.test.smtp':'diag.result.smtp.test.local'),'ok');else if(name==='push')addResult(text('qa.result.push.ok'),'','ok',{status:data.status||'OK'});return data}
  async function run(button){if(state.busy||!state.authenticated)return;const name=button.dataset.qaAction;setBusy(button,true);try{
    if(name==='runtime'){
      let failures=0;
      for(const item of ['summary','weather','engine','quality','calibration','trust']){
        try{await runOne(item)}catch(e){if(e.status===401||e.status===403)return redirectError(e.status);failures++;addResult(text('qa.result.failed',{action:text(`qa.action.${item}`)}),e.message,'bad',e.data||null)}
      }
      addResult(text('qa.result.gate.ok'),text('qa.result.gate.copy'),failures===0?'ok':'warn',{failedChecks:failures});
    } else await runOne(name);
  }catch(e){if(e.status===401||e.status===403)return redirectError(e.status);const optional=name==='push'&&e.code==='NOT_SUBSCRIBED';addResult(text('qa.result.failed',{action:text(`qa.action.${name}`)}),e.message,optional?'warn':'bad',e.data||null)}finally{setBusy(button,false)}}
  document.addEventListener('click',e=>{const b=e.target.closest?.('[data-qa-action]');if(b)run(b)});q('#qa-clear')?.addEventListener('click',()=>{if(list)list.innerHTML=`<p class="qa-empty">${safe(text('qa.results.empty'))}</p>`});
  (async()=>{try{await window.MeteoNexaI18n?.ready}catch{}try{const auth=await jsonFetch('../api/auth/status.php');state.authenticated=Boolean(auth.authenticated);if(!state.authenticated)return redirectError(401);if(auth.diagnosticsAllowed!==true)return redirectError(403);qa.hidden=false;document.body.classList.remove('qa-booting');document.body.classList.add('qa-ready');document.title=text('qa.browser_title')}catch(e){redirectError(e.status||503)}})();
})();
