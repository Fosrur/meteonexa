const PROVIDES = Object.freeze(['watchPlan']);
export const dependencies = Object.freeze(['auth', 'confirm', 'controls', 'security', 'toast']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const BUILD='20.1';
            const q=(selector,root=document)=>root.querySelector(selector);
            const qa=(selector,root=document)=>[...root.querySelectorAll(selector)];
            const safe=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
            const text=(key,params={})=>window.meteonexaText?.(key,params)||key;
            const device=()=>deps.security?.deviceId||'';
            const securityHeaders=()=>deps.security?.headers?.()||{};
            let state={plans:[],locations:[],maxPlans:8,loaded:false};
            let draft={activity:'run',locationKey:'',id:''};
            const activities=['run','bike','motorcycle','sea','trekking','kids','pets','worksite','commute','event','photography'];
            const authenticated=()=>deps.auth?.isAuthenticated?.()===true&&deps.auth?.serverVerified?.()===true;
            function toast(title,copy,type='info'){deps.toast?.(title,copy,type,4200);}
            async function api(method='GET',body=null){
                const id=device();if(!id)throw new Error(text('api.security.auth_required'));
                const url=method==='GET'?`api/plans/watch.php?deviceId=${encodeURIComponent(id)}`:'api/plans/watch.php';
                const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...securityHeaders(),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify({...body,deviceId:id}):undefined});
                let data=null;try{data=await response.json();}catch{}if(!response.ok||!data?.ok)throw new Error(text(data?.message||'watch.error.generic'));return data;
            }
            const fmtDate=value=>{try{return new Intl.DateTimeFormat(document.documentElement.lang||navigator.language||'it',{dateStyle:'medium'}).format(new Date(value));}catch{return '--';}};
            const fmtTime=value=>{try{return new Intl.DateTimeFormat(document.documentElement.lang||navigator.language||'it',{hour:'2-digit',minute:'2-digit'}).format(new Date(value));}catch{return '--';}};
            const statusLabel=status=>text(`watch.status.${status||'learning'}`);
            const riskLabel=risk=>text(`decision.risk.${risk||'none'}`);
            function planMessage(plan){const ev=plan.lastEvaluation;if(!ev)return text('watch.plan.learning');if(plan.lastNotificationKind==='degraded')return text('watch.plan.changed_bad');if(plan.lastNotificationKind==='recovered')return text('watch.plan.changed_good');return text('watch.plan.stable');}
            function render(){
                const root=q('#watch-watch-content');if(!root)return;
                if(!state.loaded){root.innerHTML=`<div class="watch-watch-empty"><svg><use href="#i-refresh"></use></svg><span>${safe(text('watch.loading'))}</span></div>`;return;}
                if(!state.plans.length){root.innerHTML=`<div class="watch-watch-empty"><svg><use href="#i-calendar"></use></svg><span>${safe(text(state.locations.length?'watch.empty':'watch.empty_locations'))}</span></div>`;return;}
                root.innerHTML=`<div class="watch-plan-list">${state.plans.map(plan=>{const ev=plan.lastEvaluation||{};const status=ev.status||'learning';const location=plan.location?.label||plan.location?.name||'--';return `<article class="watch-plan-card"><div class="watch-plan-card-head"><div><strong>${safe(text(`decision.activity.${plan.activity}`))}</strong><small>${safe(location)} · ${safe(fmtDate(plan.startsAt))} · ${safe(fmtTime(plan.startsAt))}</small></div><span class="watch-plan-status" data-status="${safe(status)}">${safe(statusLabel(status))}</span></div><div class="watch-plan-metrics"><span><small>${safe(text('watch.metric.score'))}</small><strong>${ev.score==null?'--':`${Math.round(Number(ev.score))}/100`}</strong></span><span><small>${safe(text('watch.metric.risk'))}</small><strong>${safe(ev.risk?riskLabel(ev.risk):'--')}</strong></span><span><small>${safe(text('watch.metric.confidence'))}</small><strong>${ev.confidence==null?'--':`${Math.round(Number(ev.confidence))}%`}</strong></span></div><p class="watch-plan-message">${safe(planMessage(plan))}</p><div class="watch-plan-card-actions"><button class="button outline-button" type="button" data-watch-edit="${safe(plan.id)}">${safe(text('watch.action.edit'))}</button><button class="button outline-button" type="button" data-watch-delete="${safe(plan.id)}">${safe(text('watch.action.delete'))}</button></div></article>`;}).join('')}</div>`;
            }
            async function load({quiet=false}={}){if(!authenticated()){state={plans:[],locations:[],maxPlans:8,loaded:true};render();return state;}try{const data=await api();state={plans:data.plans||[],locations:data.locations||[],maxPlans:Number(data.maxPlans||8),loaded:true};render();return state;}catch(error){state.loaded=true;render();if(!quiet)toast(text('watch.toast.error.title'),error.message,'warning');return state;}}
            function selectMarkup(id,value,options){return `<div class="meteo-select-control" data-meteo-select-control><button class="meteo-select-trigger" data-meteo-select id="${id}" type="button" value="${safe(value)}" aria-haspopup="listbox" aria-expanded="false" aria-controls="${id}-menu"><span class="meteo-select-value" data-meteo-select-value>${safe(options.find(x=>x.value===value)?.label||value)}</span><svg><use href="#i-chevron"></use></svg></button><div class="meteo-select-menu" id="${id}-menu" role="listbox" hidden>${options.map(x=>`<button class="meteo-select-option" type="button" role="option" data-meteo-option="${safe(x.value)}" aria-selected="${x.value===value?'true':'false'}"><span>${safe(x.label)}</span></button>`).join('')}</div></div>`;}
            function localDateValue(date){const y=date.getFullYear(),m=String(date.getMonth()+1).padStart(2,'0'),d=String(date.getDate()).padStart(2,'0');return `${y}-${m}-${d}`;}
            function prepareDialog(plan=null){
                draft={activity:plan?.activity||'run',locationKey:plan?.locationKey||state.locations[0]?.itemKey||'',id:plan?.id||''};
                const start=plan?.startsAt?new Date(plan.startsAt):new Date(Date.now()+3*3600*1000);const dateInput=q('#watch-plan-date');dateInput.value=localDateValue(start);dateInput.dispatchEvent(new Event('change',{bubbles:true}));
                const activityRoot=q('#watch-activity-choices');activityRoot.innerHTML=activities.map(id=>`<button type="button" data-watch-activity="${id}" class="${id===draft.activity?'is-active':''}">${safe(text(`decision.activity.${id}`))}</button>`).join('');
                const locationRoot=q('#watch-location-choices');locationRoot.innerHTML=state.locations.map(loc=>`<button type="button" data-watch-location="${safe(loc.itemKey)}" class="${loc.itemKey===draft.locationKey?'is-active':''}">${safe(loc.label||loc.name)}</button>`).join('')||`<span>${safe(text('watch.empty_locations'))}</span>`;
                const rounded=new Date(start);rounded.setMinutes(rounded.getMinutes()<30?30:0,0,0);if(start.getMinutes()>=30)rounded.setHours(rounded.getHours()+1);const time=`${String(rounded.getHours()).padStart(2,'0')}:${String(rounded.getMinutes()).padStart(2,'0')}`;const times=[];for(let h=0;h<24;h++)for(const m of [0,30])times.push({value:`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`,label:`${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`});q('#watch-time-control').innerHTML=selectMarkup('watch-plan-time',time,times);
                const duration=String(plan?.durationMinutes||120);q('#watch-duration-control').innerHTML=selectMarkup('watch-plan-duration',duration,[30,60,90,120,180,240,360].map(v=>({value:String(v),label:text('watch.duration.minutes',{value:v})})));
                deps.controls?.enhance?.(q('#watch-plan-dialog'));
            }
            function open(plan=null){if(!state.locations.length){toast(text('watch.toast.location.title'),text('watch.empty_locations'),'warning');return;}prepareDialog(plan);const dialog=q('#watch-plan-dialog');try{if(!dialog.open)dialog.showModal();}catch{dialog.setAttribute('open','');}}
            function close(){const dialog=q('#watch-plan-dialog');try{if(dialog.open)dialog.close();}catch{dialog.removeAttribute('open');}}
            async function save(){
                const date=q('#watch-plan-date')?.value||'';const time=q('#watch-plan-time')?.value||'';const duration=Number(q('#watch-plan-duration')?.value||120);if(!date||!time||!draft.locationKey){toast(text('watch.toast.validation.title'),text('watch.error.invalid'),'warning');return;}
                const startsAt=new Date(`${date}T${time}:00`).toISOString();const button=q('#watch-plan-save');button.disabled=true;try{const data=await api('POST',{action:'save',id:draft.id||undefined,activity:draft.activity,locationKey:draft.locationKey,startsAt,durationMinutes:duration});close();await load({quiet:true});toast(text('watch.toast.saved.title'),text('watch.toast.saved.copy'),'success');return data;}catch(error){toast(text('watch.toast.error.title'),error.message,'warning');}finally{button.disabled=false;}
            }
            async function remove(id){const plan=state.plans.find(x=>x.id===id);if(!plan)return;const ok=await (deps.confirm?.(text('watch.delete.title'),text('watch.delete.copy'),{confirmLabel:text('watch.action.delete'),icon:'#i-trash',kind:'danger'})??Promise.resolve(window.confirm(text('watch.delete.copy'))));if(!ok)return;try{await api('POST',{action:'delete',id});await load({quiet:true});toast(text('watch.toast.deleted.title'),text('watch.toast.deleted.copy'),'success');}catch(error){toast(text('watch.toast.error.title'),error.message,'warning');}}
            document.addEventListener('click',event=>{
                const activity=event.target.closest?.('[data-watch-activity]');if(activity){draft.activity=activity.dataset.watchActivity;qa('[data-watch-activity]').forEach(x=>x.classList.toggle('is-active',x===activity));return;}
                const location=event.target.closest?.('[data-watch-location]');if(location){draft.locationKey=location.dataset.watchLocation;qa('[data-watch-location]').forEach(x=>x.classList.toggle('is-active',x===location));return;}
                if(event.target.closest?.('#watch-watch-add')){open();return;}if(event.target.closest?.('#watch-watch-refresh')){load();return;}if(event.target.closest?.('#watch-plan-save')){save();return;}if(event.target.closest?.('[data-watch-close]')){close();return;}
                const edit=event.target.closest?.('[data-watch-edit]');if(edit){open(state.plans.find(x=>x.id===edit.dataset.watchEdit)||null);return;}const del=event.target.closest?.('[data-watch-delete]');if(del){remove(del.dataset.watchDelete);}
            });
            q('#watch-plan-dialog')?.addEventListener('cancel',event=>{event.preventDefault();close();});
            document.addEventListener('meteonexa:intelligence-data',()=>{if(!state.loaded)load({quiet:true});});
            document.addEventListener('meteonexa:ready',()=>{if(authenticated())setTimeout(()=>load({quiet:true}),900);},{once:true});
            document.addEventListener('meteonexa:auth-required',()=>{state={plans:[],locations:[],maxPlans:8,loaded:true};render();});
            provided.watchPlan=Object.freeze({build:BUILD,load,open,current:()=>state});
        })();
        }
    });
}

export const serviceNames = PROVIDES;
