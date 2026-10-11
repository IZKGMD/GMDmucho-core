(function (root) {
    'use strict';
    const normalize = value => String(value || '').normalize('NFKC').toLocaleLowerCase().replace(/\s+/g,' ').trim();
    const matches = (text, query) => normalize(query).split(' ').filter(Boolean).every(word => normalize(text).includes(word));
    const adminRoute = (href, origin) => {
        try {
            const url = new URL(href,origin);
            if (url.origin !== origin || url.pathname !== '/admin/' || !/^[a-z]+$/.test(url.searchParams.get('page') || '')) return null;
            if ([...url.searchParams.keys()].some(key => key !== 'page') || url.hash) return null;
            return '/admin/?page='+url.searchParams.get('page');
        } catch { return null; }
    };
    const rules = Object.freeze({normalize,matches,adminRoute});
    if (typeof module !== 'undefined' && module.exports) module.exports = rules;
    if (typeof document === 'undefined') return;
    root.MuchoProduct = rules;
    const storage = {
        get(key,fallback){try{return JSON.parse(localStorage.getItem(key)) ?? fallback;}catch{return fallback;}},
        set(key,value){try{localStorage.setItem(key,JSON.stringify(value));}catch{/* Optional preferences. */}}
    };
    const ru = () => document.documentElement.lang === 'ru';
    const label = (r,e) => ru() ? r : e;
    document.querySelectorAll('img[data-fallback-src],img[data-hide-on-error]').forEach(img => {
        const fallback = () => { if (img.dataset.fallbackSrc) img.src=img.dataset.fallbackSrc; else img.hidden=true; };
        img.addEventListener('error',fallback,{once:true});
        if (img.complete && img.naturalWidth===0) fallback();
    });
    let toastTimer;
    function toast(message){
        let node = document.querySelector('.mc-toast');
        if (!node){node=document.createElement('div');node.className='mc-toast';node.setAttribute('role','status');document.body.append(node);}
        node.textContent=message;node.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>{node.hidden=true;},4000);
    }
    document.querySelectorAll('[data-copy-target]').forEach(button=>{
        button.addEventListener('click',async()=>{
            const target=document.getElementById(button.dataset.copyTarget);
            if(!target)return;
            try{await navigator.clipboard.writeText(target.textContent.trim());toast(label('Команда скопирована','Command copied'));}
            catch{const range=document.createRange();range.selectNodeContents(target);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);toast(label('Команда выделена. Нажми Ctrl+C или удерживай текст для копирования.','Command selected. Press Ctrl+C or long-press to copy.'));}
        });
    });
    const menu=document.getElementById('productNavigation');
    const menuButton=document.querySelector('.mc-nav-toggle');
    if(menu && menuButton){
        const close=()=>{menu.classList.remove('is-open');menuButton.setAttribute('aria-expanded','false');};
        menuButton.addEventListener('click',()=>{const open=menu.classList.toggle('is-open');menuButton.setAttribute('aria-expanded',String(open));});
        menu.querySelectorAll('a').forEach(a=>a.addEventListener('click',close));
        document.addEventListener('keydown',event=>{if(event.key==='Escape'&&menu.classList.contains('is-open')){close();menuButton.focus();}});
        document.addEventListener('click',event=>{if(!menu.contains(event.target)&&!menuButton.contains(event.target))close();});
    }
    if(!document.body.classList.contains('mc-admin'))return;
    const body=document.body;
    const mobile=document.getElementById('mobileMenuButton');
    const overlay=document.getElementById('mobileOverlay');
    const sidebar=document.getElementById('adminNavigation');
    const main=document.getElementById('main');
    const desktop=window.matchMedia('(min-width: 981px)');
    const syncNavigation=()=>{
        const open=body.classList.contains('mobile-menu-open')&&!desktop.matches;
        if(sidebar)sidebar.inert=!desktop.matches&&!open;
        if(main)main.inert=open;
        mobile?.setAttribute('aria-expanded',String(open));
    };
    const closeMobile=()=>{body.classList.remove('mobile-menu-open');syncNavigation();};
    mobile?.addEventListener('click',()=>{const open=body.classList.toggle('mobile-menu-open');syncNavigation();if(open)sidebar?.querySelector('a')?.focus();});
    overlay?.addEventListener('click',closeMobile);
    sidebar?.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMobile));
    const collapse=document.getElementById('sidebarToggle');
    if(storage.get('mucho.control.collapsed',false)===true)body.classList.add('sidebar-collapsed');
    const syncCollapse=()=>{
        collapse?.setAttribute('aria-expanded',String(!body.classList.contains('sidebar-collapsed')));
        if(collapse){collapse.textContent=desktop.matches?'☰':'×';collapse.setAttribute('aria-label',desktop.matches?label('Свернуть меню','Collapse menu'):label('Закрыть меню','Close menu'));}
    };
    syncCollapse();
    collapse?.addEventListener('click',()=>{if(!desktop.matches){closeMobile();mobile?.focus();return;}body.classList.toggle('sidebar-collapsed');storage.set('mucho.control.collapsed',body.classList.contains('sidebar-collapsed'));syncCollapse();});
    desktop.addEventListener('change',()=>{closeMobile();syncCollapse();});
    syncNavigation();
    // Build commands from the server-rendered, RBAC-filtered navigation only.
    const entries=[];let group='';
    sidebar?.querySelectorAll('.nav-title,a').forEach(node=>{
        if(node.classList.contains('nav-title')){group=node.textContent.trim();return;}
        const href=adminRoute(node.getAttribute('href'),location.origin);
        if(href)entries.push({node,href,group});
    });
    const dialog=document.createElement('dialog');dialog.className='mc-dialog';dialog.setAttribute('aria-labelledby','commandTitle');
    const head=document.createElement('div');head.className='mc-dialog-head';
    const title=document.createElement('h2');title.id='commandTitle';
    const dismiss=document.createElement('button');dismiss.type='button';dismiss.textContent='×';dismiss.setAttribute('aria-label','Close');
    head.append(title,dismiss);
    const input=document.createElement('input');input.type='search';input.autocomplete='off';
    const commands=document.createElement('div');commands.className='mc-command-list';
    dialog.append(head,input,commands);document.body.append(dialog);
    let previousFocus;
    function renderCommands(){
        commands.replaceChildren();
        const stored=storage.get('mucho.control.recent',[]);
        const recent=Array.isArray(stored)?stored.filter(href=>entries.some(e=>e.href===href)).slice(0,5):[];
        const results=entries.filter(e=>matches(e.node.textContent+' '+e.group,input.value));
        if(!input.value.trim())results.sort((a,b)=>{const x=recent.indexOf(a.href),y=recent.indexOf(b.href);return (x<0?999:x)-(y<0?999:y);});
        results.forEach(entry=>{
            const a=document.createElement('a');a.href=entry.href;
            const name=document.createElement('span');name.textContent=entry.node.textContent.trim();
            const meta=document.createElement('small');meta.textContent=entry.group;
            a.append(name,meta);a.addEventListener('click',()=>{storage.set('mucho.control.recent',[entry.href,...recent.filter(v=>v!==entry.href)].slice(0,5));});commands.append(a);
        });
        if(!results.length){const empty=document.createElement('div');empty.className='mc-command-empty';empty.textContent=label('Разделы не найдены','No matching sections');commands.append(empty);}
    }
    const openCommands=()=>{
        previousFocus=document.activeElement;title.textContent=label('Быстрый переход','Quick navigation');input.placeholder=label('Найти раздел…','Find a section…');input.setAttribute('aria-label',input.placeholder);dismiss.setAttribute('aria-label',label('Закрыть','Close'));input.value='';renderCommands();dialog.showModal();input.focus();
    };
    document.getElementById('commandLauncher')?.addEventListener('click',openCommands);
    dismiss.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',event=>{if(event.target===dialog){const box=dialog.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)dialog.close();}});
    dialog.addEventListener('close',()=>previousFocus?.focus());
    input.addEventListener('input',renderCommands);
    input.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();commands.querySelector('a')?.focus();}if(event.key==='Enter'){event.preventDefault();commands.querySelector('a')?.click();}});
    commands.addEventListener('keydown',event=>{const links=[...commands.querySelectorAll('a')];const index=links.indexOf(document.activeElement);if(event.key==='ArrowDown'){event.preventDefault();links[(index+1)%links.length]?.focus();}if(event.key==='ArrowUp'){event.preventDefault();if(index===0)input.focus();else links[index-1]?.focus();}});
    document.addEventListener('keydown',event=>{
        if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();if(!dialog.open)openCommands();return;}
        if(event.key==='Escape'&&body.classList.contains('mobile-menu-open')){closeMobile();mobile?.focus();}
        if(event.key==='/'&&!dialog.open&&!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)&&!document.activeElement?.isContentEditable){event.preventDefault();document.getElementById('globalSearchInput')?.focus();}
    });
    document.querySelectorAll('.table').forEach((wrapper,index)=>{
        const table=wrapper.querySelector('table');
        if(!table||table.dataset.noFilter!==undefined)return;
        const rows=[...table.tBodies].flatMap(section=>[...section.rows]).filter(row=>[...row.cells].some(cell=>cell.tagName==='TD'));
        if(rows.length<8||!table.querySelector('th'))return;
        const tools=document.createElement('div');tools.className='mc-table-tools';
        const labelNode=document.createElement('label');labelNode.textContent=label('Фильтр на этой странице','Filter this page');
        const field=document.createElement('input');field.type='search';field.id='tableFilter'+index;field.placeholder=label('Текст в строках…','Text in rows…');field.autocomplete='off';
        labelNode.append(field);const counter=document.createElement('span');counter.setAttribute('role','status');
        tools.append(labelNode,counter);wrapper.prepend(tools);
        const empty=document.createElement('div');empty.className='mc-table-no-results';empty.textContent=label('Совпадений на этой странице нет','No matches on this page');empty.hidden=true;wrapper.append(empty);
        const update=()=>{let visible=0;rows.forEach(row=>{row.hidden=!matches(row.textContent,field.value);if(!row.hidden)visible++;});counter.textContent=visible+' / '+rows.length;empty.hidden=visible>0;};
        field.addEventListener('input',update);update();
    });
})(globalThis);
