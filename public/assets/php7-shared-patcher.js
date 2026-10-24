/* Resumable same-origin uploader and bounded PHP work; no third-party service. */
(() => {
  'use strict';
  const form=document.getElementById('sharedPatcher'); if(!form)return;
  const file=document.getElementById('sharedPatcherFile'), server=document.getElementById('sharedPatcherServer'), alongside=document.getElementById('sharedPatcherAlongside');
  const start=document.getElementById('sharedPatcherStart'), reset=document.getElementById('sharedPatcherReset'), status=document.getElementById('sharedPatcherStatus'), progress=document.getElementById('sharedPatcherProgress'), download=document.getElementById('sharedPatcherDownload'), report=document.getElementById('sharedPatcherReport');
  let busy=false, current=null;
  const hex=buffer=>Array.from(new Uint8Array(buffer),b=>b.toString(16).padStart(2,'0')).join('');
  const sha=async bytes=>hex(await crypto.subtle.digest('SHA-256',bytes));
  const fingerprint=async f=>{const first=new Uint8Array(await f.slice(0,65536).arrayBuffer()),last=new Uint8Array(await f.slice(Math.max(0,f.size-65536)).arrayBuffer()),bytes=new Uint8Array(first.length+last.length);bytes.set(first);bytes.set(last,first.length);return sha(bytes);};
  async function call(action, fields={}) {
    const body=new FormData();body.append('csrf',form.dataset.csrf);body.append('action','shared-patcher-'+action);
    for(const [key,value] of Object.entries(fields))body.append(key,value);
    const response=await fetch(form.dataset.endpoint,{method:'POST',credentials:'same-origin',body});
    let data;try{data=await response.json();}catch(_){throw new Error('Your session expired or the host rejected the request. Refresh the page and sign in.');}
    if(!response.ok||!data.ok)throw new Error(data.error||'The action failed.');return data;
  }
  function show(s) {
    current=s;progress.value=s.progress||0;
    const active=s.phase!=='idle';server.disabled=active;alongside.disabled=active;file.disabled=active&&s.phase!=='upload';
    if(active){server.value=s.server_url;alongside.checked=s.alongside;}
    const messages={idle:'Choose a file to begin.',upload:'Uploading: '+Math.round((s.offset||0)/(s.size||1)*100)+'%. Resuming continues from the last saved chunk.',inspect:'Inspecting the original build…',rebuild:'Replacing API URLs and rebuilding the APK…',jar:'Signing APK: v1…',directory:'Aligning APK entries…',digest:'Hashing APK content for v2 signing…',assemble:'Assembling the signed APK…',finish:'Checking the output file…',done:'Client ready.'};
    status.textContent=messages[s.phase]||s.phase;
    start.textContent=s.phase==='upload'?'Resume upload':s.phase==='idle'?'Prepare client':'Resume preparation';
    download.hidden=s.phase!=='done';report.hidden=s.phase!=='done';
    if(s.phase==='done'){
      start.disabled=true;download.href=form.dataset.endpoint+'?shared_patcher_download='+encodeURIComponent(s.id);
      const r=s.report;report.textContent='Server: '+r.server_url+'\nReplacements: '+r.replacement_count+(r.package_id?'\nPackage: '+r.package_id:'')+(r.signature_schemes?'\nSignature: '+r.signature_schemes.join(' + '):'')+'\nSHA-256: '+r.output_sha256+(r.certificate_sha256?'\nCertificate: '+r.certificate_sha256:'');
    }
  }
  async function run(s) {
    show(s);
    if(s.phase==='upload'){
      const f=file.files[0];if(!f||f.size!==s.size||await fingerprint(f)!==s.fingerprint)throw new Error('Select the same original file to resume.');
      while(s.offset<s.size){const bytes=await f.slice(s.offset,Math.min(s.offset+s.chunk_size,s.size)).arrayBuffer();
        try{s=await call('chunk',{offset:String(s.offset),chunk:new Blob([bytes]),sha256:await sha(bytes)});}catch(error){show(await call('status'));throw error;}show(s);
      }
    }
    while(s.phase!=='done'){s=await call('step');show(s);}
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;busy=true;start.disabled=true;reset.disabled=true;
    try{if(!crypto.subtle)throw new Error('Open the panel over HTTPS in a modern browser.');let s=await call('status');
      if(s.phase==='idle'){const f=file.files[0];if(!f)throw new Error('Choose an APK or EXE.');s=await call('start',{filename:f.name,size:String(f.size),server_url:server.value,alongside:alongside.checked?'1':'0',fingerprint:await fingerprint(f)});}
      await run(s);
    }catch(error){status.textContent=error.message;}finally{busy=false;start.disabled=current?.phase==='done';reset.disabled=false;}
  });
  reset.addEventListener('click',async()=>{if(busy)return;try{show(await call('reset'));start.disabled=false;}catch(error){status.textContent=error.message;}});
  call('status').then(show).catch(error=>{status.textContent=error.message;});
})();
