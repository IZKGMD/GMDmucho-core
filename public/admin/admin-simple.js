(() => {
    'use strict';
    const ru = () => document.documentElement.lang === 'ru';
    document.querySelectorAll('[data-password-confirm]').forEach(form => {
        const password = form.elements.new_password;
        const confirm = form.elements.new_password_confirm;
        const validate = () => confirm.setCustomValidity(confirm.value && password.value !== confirm.value
            ? (ru() ? 'Пароли не совпадают.' : 'Passwords do not match.') : '');
        password.addEventListener('input', validate);
        confirm.addEventListener('input', validate);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        const action = form.elements.namedItem('action')?.value || '';
        if (!form.hasAttribute('onsubmit') && (form.dataset.confirm || /(?:-delete|delete-)/.test(action))) {
            if (!window.confirm(ru() ? 'Подтвердить удаление? Это действие нельзя отменить.' : (form.dataset.confirm || 'Confirm deletion? This cannot be undone.'))) {
                event.preventDefault();
                return;
            }
        }
        // Preserve submit-button names and values used by the server.
        if (event.defaultPrevented || form.dataset.submitting === 'true') {
            if (form.dataset.submitting === 'true') event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-submitting]').forEach(form => {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
        });
    });
    const dirtyForms=new Set();
    let submitting=false;
    const changed=form=>[...form.elements].some(field=>{
        if(field.disabled||!field.name||['hidden','submit','button','reset'].includes(field.type))return false;
        if(['checkbox','radio'].includes(field.type))return field.checked!==field.defaultChecked;
        if(field.type==='file')return field.files.length>0;
        if(field.tagName==='SELECT'){const options=[...field.options];const defaults=options.filter(option=>option.defaultSelected);if(!defaults.length&&!field.multiple&&options.length)defaults.push(options[0]);return options.some(option=>option.selected!==defaults.includes(option));}
        return 'defaultValue' in field&&field.value!==field.defaultValue;
    });
    document.querySelectorAll('form[method="post"]').forEach(form=>{
        const status=document.createElement('p');status.className='mc-form-dirty';status.setAttribute('role','status');status.hidden=true;form.append(status);
        const update=()=>{const dirty=changed(form);if(dirty)dirtyForms.add(form);else dirtyForms.delete(form);status.textContent=ru()?'Есть несохранённые изменения':'Unsaved changes';status.hidden=!dirty;};
        form.addEventListener('input',update);form.addEventListener('change',update);
        form.addEventListener('reset',()=>{dirtyForms.delete(form);status.hidden=true;});
    });
    document.addEventListener('submit',event=>{
        if(event.defaultPrevented)return;
        if(event.target instanceof HTMLFormElement&&event.target.method.toLowerCase()==='post'){
            if([...dirtyForms].some(form=>form!==event.target)&&!window.confirm(ru()?'В другой форме есть несохранённые изменения. Продолжить?':'Another form has unsaved changes. Continue?')){event.preventDefault();delete event.target.dataset.submitting;event.target.removeAttribute('aria-busy');return;}
            submitting=true;
        }
    });
    window.addEventListener('beforeunload',event=>{if(!submitting&&dirtyForms.size){event.preventDefault();event.returnValue='';}});
    window.addEventListener('pageshow',()=>{submitting=false;});
})();
