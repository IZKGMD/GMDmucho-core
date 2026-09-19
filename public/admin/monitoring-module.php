<?php
declare(strict_types=1);
?>

<div class="boxgrid">

<div class="card">
<h2 style="margin-top:0">Services</h2>
<div id="v5Services">Loading…</div>
</div>

<div class="card">
<h2 style="margin-top:0">API Health</h2>
<div id="v5Endpoints">Loading…</div>
</div>

</div>

<h2>Anti-Abuse</h2>
<div class="card">
<div id="v5Abuse">Loading…</div>
</div>

<h2>Live Logs</h2>

<div class="row" style="margin-bottom:10px">
<select id="v5LogType">
<option value="php">PHP-FPM</option>
<option value="nginx">Nginx Error</option>
<option value="access">Nginx Access</option>
<option value="cloudflare">Cloudflare</option>
</select>

<button type="button" id="v5RefreshLogs">
Refresh
</button>

<label>
<input type="checkbox" id="v5AutoLogs">
Auto refresh
</label>
</div>

<pre id="v5Logs">Loading…</pre>

<script>
(() => {

const services=document.getElementById('v5Services');
const endpoints=document.getElementById('v5Endpoints');
const abuse=document.getElementById('v5Abuse');
const logs=document.getElementById('v5Logs');
const type=document.getElementById('v5LogType');
const auto=document.getElementById('v5AutoLogs');

async function json(url){
    const r=await fetch(url,{
        credentials:'same-origin',
        cache:'no-store'
    });
    return await r.json();
}

async function health(){
    try{
        const d=await json('/admin/v5-api.php?kind=health');

        services.innerHTML=d.services.map(x=>
            `<div style="margin:8px 0">
             <span class="badge ${x.ok?'ok':'bad'}">
             ${x.ok?'●':'●'} ${x.name}
             </span>
             </div>`
        ).join('');

        endpoints.innerHTML=d.endpoints.map(x=>
            `<div style="display:flex;justify-content:space-between;
            gap:10px;border-bottom:1px solid #222b39;padding:7px 0">
             <code>${x.path}</code>
             <span class="${x.ok?'ok':'bad'}">
             HTTP ${x.status}
             </span>
             </div>`
        ).join('');

    }catch(e){
        services.textContent='Health request failed';
    }
}

async function abuseLoad(){
    try{
        const d=await json('/admin/v5-api.php?kind=abuse');

        if(!d.players.length){
            abuse.innerHTML='<span class="ok">No obvious anomalies detected.</span>';
            return;
        }

        abuse.innerHTML=d.players.map(x=>
            `<div class="v4result">
             <b>#${x.account_id} ${x.username}</b><br>
             <small>
             Stars ${x.stars} ·
             Demons ${x.demons} ·
             CP ${x.creator_points} ·
             Diamonds ${x.diamonds}
             </small>
             </div>`
        ).join('');

    }catch(e){
        abuse.textContent='Anti-abuse request failed';
    }
}

async function loadLogs(){
    try{
        const d=await json(
            '/admin/v5-api.php?kind=logs&type='+
            encodeURIComponent(type.value)
        );

        logs.textContent=d.text || 'No log output';
        logs.scrollTop=logs.scrollHeight;

    }catch(e){
        logs.textContent='Log request failed';
    }
}

document.getElementById('v5RefreshLogs')
?.addEventListener('click',loadLogs);

type?.addEventListener('change',loadLogs);

setInterval(()=>{
    health();

    if(auto?.checked){
        loadLogs();
    }
},5000);

health();
abuseLoad();
loadLogs();

})();
</script>
