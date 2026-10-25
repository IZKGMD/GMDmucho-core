/* Progressive motion for native server-rendered administration. No request interception. */
(() => {
    'use strict';
    const body = document.body;
    if (!body.matches('.mc-admin, .admin-login.mc-workspace') || !Element.prototype.animate) return;
    const preference = matchMedia('(prefers-reduced-motion: reduce)');
    const ease = 'cubic-bezier(.22,1,.36,1)';
    const running = new Set();
    const panels = new WeakMap();
    function animate(element, frames, options) {
        const effect = element.animate(frames, options);
        running.add(effect);
        effect.finished.then(() => running.delete(effect), () => running.delete(effect));
        return effect;
    }
    function reveal() {
        if (preference.matches) return;
        const main = document.querySelector('.shell>main, .admin-auth-card');
        if (!main) return;
        const items = [...main.children].filter(el => !el.matches('script,style,footer') && el.getBoundingClientRect().height > 0).slice(0,12);
        items.forEach((el, index) => animate(el, [
            {opacity:0, transform:'translateY(12px)'},
            {opacity:1, transform:'translateY(0)'}
        ], {duration:420, delay:Math.min(index*35,175), easing:ease}));
    }
    // Native document transitions handle navigation; this is the first-load fallback.
    if ('onpagereveal' in window) {
        window.addEventListener('pagereveal', event => { if (!event.viewTransition) reveal(); }, {once:true});
    } else {
        window.addEventListener('pageshow', event => { if (!event.persisted) reveal(); }, {once:true});
    }
    body.querySelectorAll('details').forEach(details => {
        const summary = details.querySelector(':scope>summary');
        if (!summary) return;
        summary.addEventListener('click', event => {
            if (preference.matches || event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (event.target.closest('a,button,input,select,textarea,label')) return;
            event.preventDefault();
            const previous = panels.get(details);
            const expanded = previous ? !previous.expanded : !details.open;
            const start = details.getBoundingClientRect().height;
            if (previous) previous.effect.cancel();
            const popover = details.querySelector(':scope>.mc-view-popover');
            let end;
            details.open = expanded;
            end = details.getBoundingClientRect().height;
            // Keep the contents rendered until the closing animation completes.
            details.open = true;
            if (!expanded && details.contains(document.activeElement) && !summary.contains(document.activeElement)) summary.focus();
            const frames = popover ? (expanded ? [
                {opacity:0, transform:'translateY(-6px) scale(.97)'}, {opacity:1, transform:'translateY(0) scale(1)'}
            ] : [
                {opacity:1, transform:'translateY(0) scale(1)'}, {opacity:0, transform:'translateY(-4px) scale(.98)'}
            ]) : [{height:start+'px'},{height:end+'px'}];
            if (!popover) details.classList.add('mc-detail-moving');
            const effect = animate(popover || details, frames, {
                duration:popover ? 200 : Math.min(360,Math.max(220,180+Math.abs(end-start)*.15)),
                easing:ease, fill:'both'
            });
            const current = {effect, expanded};
            panels.set(details,current);
            effect.onfinish = () => {
                if (panels.get(details) !== current) return;
                details.open = expanded;
                details.classList.remove('mc-detail-moving');
                panels.delete(details);
                effect.cancel();
            };
        });
    });
    preference.addEventListener('change', () => {
        if (preference.matches) [...running].forEach(effect => effect.finish());
    });
    window.addEventListener('pagehide', () => {
        // BFCache must store final, expanded states instead of animation frames.
        [...running].forEach(effect => effect.finish());
    });
})();
