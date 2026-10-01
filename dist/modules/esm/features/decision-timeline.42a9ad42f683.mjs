const PROVIDES = Object.freeze(['decisionTimeline']);
export const dependencies = Object.freeze(['intelligence']);

export function install(services, host = globalThis) {
    return services.installModule({
        services,
        host,
        provides: PROVIDES,
        dependencies,
        factory(window, deps, provided) {
        (() => {
            const q=(selector,root=document)=>root.querySelector(selector);
            const qa=(selector,root=document)=>[...root.querySelectorAll(selector)];
            const safe=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
            const text=(key,params={})=>window.meteonexaText?.(key,params)||key;
            let current=null,activity='run';
            const localTime=value=>{try{return new Intl.DateTimeFormat(document.documentElement.lang||navigator.language||'it',{hour:'2-digit',minute:'2-digit'}).format(new Date(value));}catch{return '--';}};
            const activityLabel=id=>text(`decision.activity.${id}`);
            const windowLabel=range=>range?.startsAt?text('decision.decision.window',{from:localTime(range.startsAt),to:localTime(range.endsAt),score:Math.round(Number(range.score||0))}):text('decision.decision.window_none');
            function renderDecision(data){
                const root=q('#decision-decision-content');if(!root)return;qa('[data-decision-activity]').forEach(button=>button.classList.toggle('is-active',button.dataset.decisionActivity===activity));const payload=data?.decisionTimeline||{};const map=payload.activities||{};const selected=map[activity]||map[payload.defaultActivity]||Object.values(map)[0];
                if(!payload.available||!selected){root.innerHTML=`<div class="intel-empty">${safe(text('decision.decision.unavailable'))}</div>`;return;}
                const rows=(selected.timeline||[]).slice(0,12);const best=windowLabel(selected.bestWindow),alt=windowLabel(selected.alternativeWindow);
                root.innerHTML=`<div class="decision-decision-summary"><div><small>${safe(text('decision.decision.best'))}</small><strong>${safe(best)}</strong></div><div><small>${safe(text('decision.decision.alternative'))}</small><strong>${safe(alt)}</strong></div></div><div class="decision-decision-timeline">${rows.map(row=>`<article class="decision-decision-point" data-status="${safe(row.status||'caution')}"><time>${safe(localTime(row.time))}</time><span class="decision-decision-dot" aria-hidden="true"></span><div><strong>${Math.round(Number(row.score||0))}/100</strong><small>${safe(text(`decision.risk.${row.risk||'none'}`))}</small><span>${safe(text('decision.decision.confidence',{value:Math.round(Number(row.confidence||0))}))}</span></div></article>`).join('')}</div><div class="decision-decision-legend"><span>${safe(text('decision.status.good'))}</span><span>${safe(text('decision.status.caution'))}</span><span>${safe(text('decision.status.avoid'))}</span></div>`;
            }
            function changeCopy(event){
                if(event.kind==='nowcast_confirmation')return text('decision.change.radar',{eta:event.etaMinutes==null?'--':Math.max(0,Math.round(Number(event.etaMinutes))),confidence:Math.round(Number(event.confidence||0))});
                const parts=[];if(event.typeChanged)parts.push(text('decision.change.type'));if(Math.abs(Number(event.timeShiftMinutes||0))>=30)parts.push(Number(event.timeShiftMinutes)<0?text('decision.change.earlier',{minutes:Math.abs(Number(event.timeShiftMinutes))}):text('decision.change.later',{minutes:Math.abs(Number(event.timeShiftMinutes))}));if(Math.abs(Number(event.rainProbabilityDelta||0))>=15)parts.push(text('decision.change.rain',{delta:Number(event.rainProbabilityDelta)>0?`+${event.rainProbabilityDelta}`:String(event.rainProbabilityDelta)}));if(Math.abs(Number(event.agreementDelta||0))>=15)parts.push(text('decision.change.agreement',{delta:Number(event.agreementDelta)>0?`+${event.agreementDelta}`:String(event.agreementDelta)}));return parts.join(' · ')||text('decision.change.material');
            }
            function renderChange(data){
                const root=q('#intelq-change-content');if(!root)return;const payload=data?.forecastChangeV2||{};
                if(data?.mode==='guest-demo'||data?.privacy?.historyPersisted===false){root.innerHTML=`<div class="intelq-empty">${safe(text('intelq.change.guest_demo'))}</div>`;return;}
                if(!payload.available){root.innerHTML=`<div class="intelq-empty">${safe(text('decision.change.learning'))}</div>`;return;}
                root.innerHTML=`<div class="decision-change-list">${(payload.events||[]).slice(0,6).map(event=>`<article class="decision-change-event" data-kind="${safe(event.kind||'forecast')}"><time>${safe(localTime(event.at))}</time><div><strong>${safe(event.kind==='nowcast_confirmation'?text('decision.change.radar_title'):text('decision.change.forecast_title'))}</strong><p>${safe(changeCopy(event))}</p><small>${safe(text('decision.change.impact',{value:Math.round(Number(event.impactScore||0))}))}</small></div></article>`).join('')}</div><div class="decision-change-foot"><span>${safe(text('decision.change.noise'))}</span>${payload.notifyRecommended?`<span class="soft-badge">${safe(text('decision.change.notify'))}</span>`:''}</div>`;
            }
            function syncActivity(){const active=q('[data-personal-activity].is-active');if(active?.dataset?.personalActivity&&current?.decisionTimeline?.activities?.[active.dataset.personalActivity])activity=active.dataset.personalActivity;renderDecision(current);}
            function render(data){current=data||current;if(!current)return;syncActivity();renderChange(current);}
            document.addEventListener('click',event=>{const button=event.target.closest?.('[data-decision-activity],[data-personal-activity]');if(!button)return;const id=button.dataset.decisionActivity||button.dataset.personalActivity;if(current?.decisionTimeline?.activities?.[id]){activity=id;setTimeout(()=>renderDecision(current),0);}});
            document.addEventListener('meteonexa:intelligence-data',event=>render(event.detail?.data));
            document.addEventListener('meteonexa:ready',()=>setTimeout(()=>render(deps.intelligence?.current?.()),650));
            provided.decisionTimeline=Object.freeze({render,current:()=>current});
        })();
        }
    });
}

export const serviceNames = PROVIDES;
