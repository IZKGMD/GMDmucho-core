(() => {
    'use strict';
    const root=document.getElementById('muchoLiveAudit');
    const state=document.getElementById('auditLiveState');
    if(!root||!state)return;
    let active=false;
    const ru=()=>document.documentElement.lang==='ru';
    async function refresh(){
        if(active||document.hidden)return;
        active=true;
        const controller=new AbortController();
        const timer=setTimeout(()=>controller.abort(),10000);
        try{
            const response=await fetch('/admin/?audit_feed=1',{signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
            if(!response.ok)throw new Error('audit');
            const data=await response.json();
            if(data.ok!==true||!Array.isArray(data.events))throw new Error('audit schema');
            root.replaceChildren();
            data.events.slice(0,20).forEach(item=>{
                const row=document.createElement('div');row.className='admin-live-audit-row';
                for(const [tag,key,css] of [['small','created_at',''],['b','username',''],['span','action','admin-live-audit-action'],['span','target','admin-live-audit-target']]){
                    const field=document.createElement(tag);field.className=css;field.textContent=String(item[key]??'—');row.append(field);
                }
                root.append(row);
            });
            if(!data.events.length){const empty=document.createElement('div');empty.className='empty-state';empty.textContent=ru()?'Пока нет событий администраторов.':'No administrator events yet.';root.append(empty);}
            state.textContent='LIVE';state.classList.add('green');
        }catch{
            state.textContent=ru()?'Нет связи':'Unavailable';state.classList.remove('green');
            if(!root.querySelector('.admin-live-audit-row'))root.textContent=ru()?'Не удалось загрузить журнал. Повторим автоматически.':'Could not load the audit feed. Retrying automatically.';
        }finally{clearTimeout(timer);active=false;}
    }
    refresh();
    setInterval(refresh,15000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
