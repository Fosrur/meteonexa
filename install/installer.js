'use strict';
(() => {
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => [...r.querySelectorAll(s)];
  const i18n = { required: document.body.dataset.msgRequired || '', email: document.body.dataset.msgEmail || '', smtp: document.body.dataset.msgSmtp || '' };
  const toast = $('#install-toast');
  const showToast = message => { if(!toast) return; toast.textContent=String(message||''); toast.hidden=false; clearTimeout(showToast.timer); showToast.timer=setTimeout(()=>{toast.hidden=true;},5200); };
  const validEmail = value => !value || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(String(value).trim());
  const closeMenus = except => {
    $$('[data-select-menu],[data-choice-menu]').forEach(menu => { if(menu===except) return; menu.hidden=true; const btn=menu.parentElement?.querySelector('[aria-expanded]'); btn?.setAttribute('aria-expanded','false'); });
  };
  $$('[data-select-button],[data-choice-button]').forEach(button => button.addEventListener('click', event => {
    event.preventDefault(); const menu=button.parentElement.querySelector('[role=listbox]'); const open=menu?.hidden===false; closeMenus(menu); if(menu){menu.hidden=open;button.setAttribute('aria-expanded',String(!open));if(!open)menu.querySelector('[aria-selected=true],button')?.focus();}
  }));
  $$('[data-choice]').forEach(root => root.querySelectorAll('[role=option]').forEach(option => option.addEventListener('click', () => {
    const input=$('input[type=hidden]',root), label=$('[data-choice-label]',root), menu=$('[data-choice-menu]',root), button=$('[data-choice-button]',root);
    if(input) input.value=option.dataset.value||''; if(label) label.textContent=option.dataset.label||option.textContent; root.querySelectorAll('[role=option]').forEach(x=>x.setAttribute('aria-selected',String(x===option))); if(menu)menu.hidden=true;button?.setAttribute('aria-expanded','false');button?.focus();
  })));
  $$('[data-language]').forEach(button => button.addEventListener('click', () => { const url=new URL(location.href);url.searchParams.set('lang',button.dataset.language||'it');location.href=url.toString(); }));
  $$('[data-password-toggle]').forEach(button => button.addEventListener('click', () => { const input=button.parentElement?.querySelector('input'); if(!input)return; input.type=input.type==='password'?'text':'password'; }));
  document.addEventListener('pointerdown', e => { if(!e.target.closest('[data-custom-select],[data-choice]')) closeMenus(); });
  const form=$('#install-form');
  form?.addEventListener('submit', event => {
    form.querySelectorAll('.is-invalid').forEach(el=>el.classList.remove('is-invalid'));
    const missing=$$('[data-required]',form).filter(input=>!String(input.value||'').trim());
    if(missing.length){event.preventDefault();missing.forEach(x=>x.classList.add('is-invalid'));missing[0]?.focus();showToast(i18n.required);return;}
    const emailFields=['qa_admin_email','smtp_from_email'].map(name=>form.elements.namedItem(name)).filter(Boolean);
    if(emailFields.some(input=>!validEmail(input.value))){event.preventDefault();emailFields.filter(input=>!validEmail(input.value)).forEach(x=>x.classList.add('is-invalid'));showToast(i18n.email);return;}
    const smtpRequired=['smtp_username','smtp_from_email','smtp_password'].map(name=>String(form.elements.namedItem(name)?.value||'').trim());
    const smtpStarted=smtpRequired.some(Boolean); if(smtpStarted && smtpRequired.some(v=>!v)){event.preventDefault();showToast(i18n.smtp);return;}
    $('#install-loader')?.classList.add('active'); $('#install-loader')?.setAttribute('aria-hidden','false'); form.querySelectorAll('button,a').forEach(el=>{if('disabled' in el)el.disabled=true;});
  });
})();
