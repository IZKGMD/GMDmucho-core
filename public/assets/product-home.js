(() => {
    'use strict';
    let language = 'ru';
    const translations = [...document.querySelectorAll('[data-en]')].map(node => ({node, ru: node.textContent, en: node.dataset.en}));
    // Translate leaf labels only: nested artwork and semantic markup stay intact.
    translations.forEach(item => { if (item.node.children.length) item.node.removeAttribute('data-en'); });
    const labels = translations.filter(item => !item.node.children.length);
    const switcher = document.getElementById('productLanguage');
    let snapshot = null;
    let failed = false;
    const words = {
        ru: {loading:'Загрузка…',ok:'Данные сервера',error:'Данные недоступны',empty:'Пока нет опубликованных уровней.',retry:'Не удалось загрузить данные. Попробуй обновить ещё раз.',updated:'Обновлено',by:'Автор',downloads:'скачиваний'},
        en: {loading:'Loading…',ok:'Server data',error:'Data unavailable',empty:'No published levels yet.',retry:'Could not load community data. Try refreshing again.',updated:'Updated',by:'By',downloads:'downloads'}
    };
    function applyLanguage(next){
        language = next === 'en' ? 'en' : 'ru';
        document.documentElement.lang = language;
        labels.forEach(({node,ru,en}) => {node.textContent = language === 'en' ? en : ru;});
        switcher.textContent = language === 'en' ? 'RU' : 'EN';
        switcher.setAttribute('aria-label', language === 'en' ? 'Switch to Russian' : 'Switch to English');
        if (snapshot || failed) render();
        else document.getElementById('communityState').textContent = words[language].loading;
    }
    try { applyLanguage(localStorage.getItem('mucho.product.language') || 'ru'); } catch { applyLanguage('ru'); }
    switcher.addEventListener('click', () => {
        applyLanguage(language === 'ru' ? 'en' : 'ru');
        try { localStorage.setItem('mucho.product.language', language); } catch { /* Private browsing can block storage. */ }
    });
    const panel = document.getElementById('communityPanel');
    const state = document.getElementById('communityState');
    const list = document.getElementById('communityLevels');
    const refresh = document.getElementById('communityRefresh');
    const number = value => { const n=Number(value); return new Intl.NumberFormat(language).format(Number.isFinite(n) ? Math.max(0,n) : 0); };
    function render(){
        const w = words[language];
        state.textContent = failed ? w.error : w.ok;
        state.dataset.state = failed ? 'error' : 'ok';
        for (const key of ['players','levels','comments']) {
            document.getElementById('stat'+key[0].toUpperCase()+key.slice(1)).textContent = failed ? '—' : number(snapshot.stats[key]);
        }
        list.replaceChildren();
        if (failed || !snapshot.latest_levels.length) {
            const empty = document.createElement('p');
            empty.className = 'mc-live-empty';
            empty.textContent = failed ? w.retry : w.empty;
            list.append(empty);
            return;
        }
        snapshot.latest_levels.slice(0,4).forEach(level => {
            const row = document.createElement('div'); row.className = 'mc-level';
            const info = document.createElement('div');
            const title = document.createElement('b'); title.textContent = String(level.name || '—');
            const sub = document.createElement('small'); sub.textContent = `${w.by} ${String(level.creator || '—')} · #${number(level.id)}`;
            const stars = document.createElement('span'); stars.textContent = `${number(level.stars)} ★`;
            info.append(title,sub); row.append(info,stars); list.append(row);
        });
    }
    let active = false;
    async function load(){
        if (active) return;
        active = true; refresh.disabled = true; panel.setAttribute('aria-busy','true');
        state.textContent = words[language].loading;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('/api/v2/dashboard.php',{signal:controller.signal,cache:'no-store',headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('community');
            const data = await response.json();
            if (data.ok !== true || !data.stats || !Array.isArray(data.latest_levels)) throw new Error('community schema');
            snapshot = data; failed = false;
        } catch { failed = true; }
        finally { clearTimeout(timeout); active = false; refresh.disabled = false; panel.setAttribute('aria-busy','false'); render(); }
    }
    refresh.addEventListener('click',load);
    load();
    setInterval(() => { if (!document.hidden) load(); },60000);
})();
