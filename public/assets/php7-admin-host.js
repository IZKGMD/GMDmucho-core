/* Only the host integration differs from the original VPS scripts. */
(() => {
    document.querySelectorAll('[data-copy-invite]').forEach(button=>{
        button.addEventListener('click',async()=>{
            const input=button.previousElementSibling;
            try{await navigator.clipboard.writeText(input.value);button.textContent='Copied';}
            catch{input.select();document.execCommand('copy');button.textContent='Copied';}
        });
    });
    document.querySelectorAll('form[data-confirm]').forEach(form=>{
        form.addEventListener('submit',event=>{if(!confirm(form.dataset.confirm))event.preventDefault();});
    });
    const link=document.querySelector('.admin-nav a.logout');
    link?.addEventListener('click',event=>{
        event.preventDefault();
        const form=document.createElement('form');form.method='post';form.action=document.body.dataset.adminPath;
        for(const [name,value] of [['csrf',document.body.dataset.csrf],['action','logout']]){
            const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);
        }
        document.body.append(form);form.submit();
    });
})();
