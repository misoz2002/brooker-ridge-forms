document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('.brah-form').forEach(form=>{
  const token=form.querySelector('[name="captcha_token"]');
  try{const data=JSON.parse(atob(token.value));const answer=Number(data.a);const a=Math.max(2,Math.min(9,Math.floor(answer/2)));form.querySelector('.captcha-question').textContent=`${a} + ${answer-a}`;}catch(e){}
  const valueOf=name=>{const controls=[...form.querySelectorAll('[name]')].filter(x=>x.name===name||x.name===name+'[]');const checked=controls.filter(x=>(x.type==='checkbox'||x.type==='radio')&&x.checked).map(x=>x.value);if(checked.length)return checked;return controls[0]?.value??''};
  const sync=()=>form.querySelectorAll('[data-condition-field]').forEach(box=>{const expected=box.dataset.conditionValue||'',actual=valueOf(box.dataset.conditionField),show=Array.isArray(actual)?actual.includes(expected):String(actual)===expected;box.hidden=!show;box.querySelectorAll('input,select,textarea,button').forEach(x=>x.disabled=!show)});
  const button=form.querySelector('button[type="submit"]'),original=button?.textContent||'';const restore=()=>{if(button){button.disabled=false;button.textContent=original;button.removeAttribute('aria-busy')}form.classList.remove('is-submitting')};
  form.addEventListener('change',sync);form.addEventListener('submit',()=>{sync();form.classList.add('is-submitting');if(button){button.disabled=true;button.textContent='Sending…';button.setAttribute('aria-busy','true')}});window.addEventListener('pageshow',restore);sync();
}));
