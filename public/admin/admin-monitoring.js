(() => {
    'use strict';

    const serviceRoot=document.getElementById('v5Services');
    if (!serviceRoot) return;
    const endpointsRoot=document.getElementById('v5Endpoints');
    const anomalyRoot=document.getElementById('v5Abuse');
    const checksRoot=document.getElementById('v5Checks');
    const checked=document.getElementById('v5LastChecked');
    const logs=document.getElementById('v5Logs');
    const logType=document.getElementById('v5LogType');
    const autoLogs=document.getElementById('v5AutoLogs');
    let healthBusy=false,logsBusy=false;

    const message=(node,value)=>{
        if (!node) return;
        node.textContent=String(value);
    };
    const row=(label,status,detail,override)=>{
        const entry=document.createElement('div');
        entry.className='admin-monitor-row';
        const name=document.createElement('strong');
        name.textContent=String(label);
        const value=document.createElement('span');
        const mode=String(status || 'unknown');
        value.className='admin-monitor-status admin-monitor-'+
            (['online','warning','unknown'].includes(mode)?mode:'unknown');
        value.textContent=override || (mode==='online'?'Responding':(mode==='warning'?'Needs attention':'Not measured'));
        entry.append(name,value);
        if (detail) {
            const note=document.createElement('small');
            note.textContent=String(detail);
            entry.append(note);
        }
        return entry;
    };
    async function fetchJson(url,timeout=15000) {
        const controller=new AbortController();
        const timer=setTimeout(()=>controller.abort(),timeout);
        try {
            const response=await fetch(url,{
                credentials:'same-origin',cache:'no-store',
                headers:{Accept:'application/json'},signal:controller.signal
            });
            if (!response.ok) throw new Error('HTTP '+response.status);
            const data=await response.json();
            if (!data||typeof data!=='object'||data.error) {
                throw new Error(String(data?.error || 'Invalid response'));
            }
            return data;
        } finally { clearTimeout(timer); }
    }
    async function health() {
        if (healthBusy || document.hidden) return;
        healthBusy=true;
        message(checked,'Checking…');
        try {
            const data=await fetchJson('/admin/v5-api.php?kind=health',45000);
            if (!Array.isArray(data.services)||!Array.isArray(data.endpoints))
                throw new Error('Missing health measurements');
            const services=data.services.map(item=>row(
                item.name,item.state,
                item.detail || ''
            ));
            serviceRoot.replaceChildren(...services);
            const endpoints=data.endpoints.map(item=>row(
                item.path,item.ok?'online':(item.status?'warning':'unknown'),
                item.status?'HTTP '+item.status:'No HTTP response'
            ));
            endpointsRoot.replaceChildren(...endpoints);
            if (checksRoot) {
                const checks=Array.isArray(data.checks) ? data.checks.map(item=>{
                    const measured=item.count!==null && Number.isFinite(Number(item.count));
                    const count=measured?Number(item.count):null;
                    return row(
                        item.name,
                        !measured?'unknown':(count>0?'warning':'online'),
                        !measured?'Not available':count+' · '+String(item.hint || ''),
                        !measured?'Unavailable':(count>0?'Review':'Clear')
                    );
                }) : [];
                checksRoot.replaceChildren(...checks);
                if (!checks.length) message(checksRoot,'No diagnostic counters available.');
            }
            message(checked,'Checked: '+String(data.time || 'just now'));
        } catch(error) {
            message(checked,'Health checks unavailable: '+error.message);
            message(serviceRoot,'Could not load health measurements. Use sudo mucho doctor on the VPS.');
            message(endpointsRoot,'No endpoint measurements available.');
            message(checksRoot,'No diagnostic counters available.');
        } finally { healthBusy=false; }
    }
    async function abuse() {
        try {
            const data=await fetchJson('/admin/v5-api.php?kind=abuse');
            if (!Array.isArray(data.players)) throw new Error('Invalid account data');
            if (!data.players.length) {
                message(anomalyRoot,'No accounts exceed the configured review thresholds.');
                return;
            }
            const records=data.players.slice(0,100).map(player=>{
                const entry=document.createElement('div');entry.className='admin-monitor-row';
                const heading=document.createElement('strong');
                heading.textContent='#'+Number(player.account_id || 0)+' '+String(player.username || 'Unknown');
                const stats=document.createElement('small');
                stats.textContent='Stars '+Number(player.stars||0)+' · Demons '+Number(player.demons||0)+
                    ' · CP '+Number(player.creator_points||0)+' · Diamonds '+Number(player.diamonds||0);
                entry.append(heading,stats);
                return entry;
            });
            anomalyRoot.replaceChildren(...records);
        } catch {
            message(anomalyRoot,'Unable to read account review data.');
        }
    }
    async function loadLogs() {
        if (!logs || logsBusy || document.hidden) return;
        logsBusy=true;message(logs,'Loading log output…');
        try {
            const kind=encodeURIComponent(logType?.value || 'php');
            const data=await fetchJson('/admin/v5-api.php?kind=logs&type='+kind);
            message(logs,data.text || 'No log output returned.');
        } catch(error) {
            message(logs,'Log request unavailable: '+error.message);
        } finally { logsBusy=false; }
    }

    document.getElementById('v5RefreshHealth')?.addEventListener('click',health);
    document.getElementById('v5RefreshAbuse')?.addEventListener('click',abuse);
    document.getElementById('v5RefreshLogs')?.addEventListener('click',loadLogs);
    logType?.addEventListener('change',()=>{
        if (autoLogs?.checked) loadLogs();
    });
    document.addEventListener('visibilitychange',()=>{if (!document.hidden) health();});
    health();abuse();
    // Slow polling only when the page is visible, without overlapping requests.
    setInterval(()=>{
        if (document.hidden) return;
        health();
        if (autoLogs?.checked) loadLogs();
    },60000);
})();
