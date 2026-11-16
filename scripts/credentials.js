/* Only status crosses the read boundary. Values exist only in password inputs
   until a request completes; never in storage, application config or caches. */
(()=>{
 const nativeFetch=window.fetch.bind(window);let states={};
 const api=async data=>{const r=await nativeFetch('endpoints/settings/credentials.php',{method:'POST',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':window.csrfToken},body:JSON.stringify(data)});return r.json()};
 const page=location.pathname.split('/').pop();
 const nameFor=(name,e)=>{
  if(name.startsWith('exchange'))return document.getElementById('fixerProvider')?.value===name.slice(-1);
  if(e.site)return page==='admin.php';
  return page!=='admin.php';
 };
 async function refresh(){
  if(!window.csrfToken)return;
  const result=await api({action:'status'});if(!result.success)return;states=result.credentials;
  for(const [name,e] of Object.entries(states)){
   if(!nameFor(name,e))continue;
   const input=document.getElementById(e.input);if(!input)continue;
   input.type='password';input.removeAttribute('readonly');input.disabled=false;input.value='';
   input.placeholder='留空保留现有配置';input.autocomplete='new-password';
   let box=input.parentElement.querySelector('.credential-tools[data-for="'+e.input+'"]');
   if(!box){box=document.createElement('div');box.className='credential-tools';box.dataset.for=e.input;input.insertAdjacentElement('afterend',box)}
   const noKey=name==='ai'&&document.getElementById('ai_type')?.value==='ollama';input.hidden=noKey;box.hidden=false;
   box.replaceChildren();const status=document.createElement('span');status.className='credential-status';status.textContent=noKey?'无需密钥':(e.configured?'已配置':'未配置');box.append(status);if(noKey)continue;
   for(const [action,label] of [['save','保存密钥'],['test','测试'],['delete','删除密钥']]){
    const btn=document.createElement('button');btn.type='button';btn.textContent=label;btn.className=action==='delete'?'secondary-button credential-delete':'secondary-button';
    btn.onclick=async()=>{if(action==='delete'&&!confirm('确认删除当前密钥？删除后不会读取旧挂载配置。'))return;btn.disabled=true;
     try{const value=input.value;const reply=await api({action,name,value,confirm:action==='delete'});if(reply.success){input.value='';showSuccessMessage(reply.message);await refresh()}else showErrorMessage(reply.message)}catch{showErrorMessage('请求失败，原配置未改动。')}finally{btn.disabled=false}
    };box.append(btn)
   }
  }
  updateAiLabel();
 }
 function updateAiLabel(){const toggle=document.getElementById('ai_enabled');const label=document.querySelector('label[for="ai_enabled"]');if(toggle&&label)label.textContent=toggle.checked?'已开启':'已关闭'}
 document.addEventListener('DOMContentLoaded',()=>{refresh();document.getElementById('fixerProvider')?.addEventListener('change',refresh);document.getElementById('ai_enabled')?.addEventListener('change',updateAiLabel);document.getElementById('ai_type')?.addEventListener('change',refresh)});
 // Legacy non-secret form saves preserve the saved value server-side, then clear inputs.
 window.fetch=async(...args)=>{const response=await nativeFetch(...args);const url=String(args[0]);if(!url.includes('credentials.php')&&/\/(save[^/]*|google_search|fixer_api_key|regenerateapikey)\.php/.test(url)){
  response.clone().json().then(data=>{if(data.success){for(const e of Object.values(states)){const input=document.getElementById(e.input);if(input)input.value=''}refresh()}}).catch(()=>{});
 }return response};
 window.wallosCredentialRefresh=refresh;
})();
(()=>{
 const providers={0:{name:'Fixer',info:'使用 Fixer 汇率数据。',apply:'https://fixer.io/signup',help:'https://fixer.io/documentation'},1:{name:'APILayer',info:'使用 APILayer 的 Fixer 接口。',apply:'https://apilayer.com/marketplace/fixer-api',help:'https://apilayer.com/marketplace/fixer-api'},2:{name:'Frankfurter',info:'公开参考汇率，无需密钥。',help:'https://frankfurter.dev'},3:{name:'AllRatesToday',info:'公开参考汇率，无需密钥。',help:'https://allratestoday.com'},4:{name:'ExchangeRate-API',info:'使用 ExchangeRate-API 汇率数据。',apply:'https://www.exchangerate-api.com',help:'https://www.exchangerate-api.com/docs/standard-requests'}};
 let pending=false,last;
 const post=async(path,data={})=>{const r=await fetch(path,{method:'POST',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':window.csrfToken},body:new URLSearchParams(data)});return r.json()};
 function describe(){const select=document.getElementById('fixerProvider');if(!select)return;const id=select.value,p=providers[id];document.getElementById('exchangeDescription').textContent=p.info;const noKey=id==='2'||id==='3';document.getElementById('exchangeKeyFields').hidden=noKey;const a=document.getElementById('exchangeApply');a.hidden=noKey;if(p.apply)a.href=p.apply;document.getElementById('exchangeHelp').href=p.help;if(noKey)document.getElementById('exchangeConfigured').textContent='配置状态：无需密钥';else if(last&&String(last.provider)===id)document.getElementById('exchangeConfigured').textContent='配置状态：'+(last.configured?'已配置':'未配置');else document.getElementById('exchangeConfigured').textContent='保存配置后切换到此提供商';}
 async function load(){if(!document.getElementById('fixerProvider'))return;last=await post('endpoints/currency/status.php');if(!last.success)return;describe();document.getElementById('exchangeUpdated').textContent='最近成功更新：'+(last.last_success?new Date(last.last_success).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai'}):'尚未获取汇率');document.getElementById('exchangeMessage').textContent=last.message;const result=document.getElementById('exchangeRates');result.replaceChildren();document.getElementById('exchangeResultSource').textContent=last.last_success?'上次成功来源：'+(providers[last.cache_provider]?.name||'原配置'):'尚无成功汇率';if(last.last_success)for(const r of last.rates){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=r.code;dd.textContent=Number(r.rate).toPrecision(7);result.append(dt,dd)}}
 window.addFixerKeyButton=async()=>{const button=document.getElementById('addFixerKey');button.disabled=true;try{const reply=await post('endpoints/currency/fixer_api_key.php',{provider:document.getElementById('fixerProvider').value,api_key:document.getElementById('fixerKey').value});if(reply.success){document.getElementById('fixerKey').value='';showSuccessMessage(reply.message);await window.wallosCredentialRefresh?.();await load()}else showErrorMessage(reply.message)}catch{showErrorMessage('保存失败，原配置未改动。')}finally{button.disabled=false}};
 document.addEventListener('DOMContentLoaded',()=>{const select=document.getElementById('fixerProvider');if(!select)return;select.addEventListener('change',describe);const button=document.getElementById('refreshExchange');button.onclick=async()=>{if(pending)return;pending=true;button.disabled=true;try{const reply=await post('endpoints/currency/update_exchange.php',{force:'true'});document.getElementById('exchangeMessage').textContent=reply.message;await load()}catch{showErrorMessage('刷新失败，已保留缓存。')}finally{pending=false;button.disabled=false}};load()});
})();
