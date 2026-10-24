(() => {
  'use strict';
  const root = document.getElementById('sharedPasskeys');
  if (!root) return;
  const status = document.getElementById('sharedPasskeyStatus');
  const start = document.getElementById('sharedPasskeyStart');
  const decode = text => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  const encode = buffer => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const field = id => document.getElementById(id)?.value || '';
  async function call(operation, extra = {}) {
    const body = new FormData();
    const fields = {action: 'shared-passkey-' + operation, csrf: root.dataset.csrf, current_password: field('passkeyCurrentPassword'), otp: field('passkeyOtp'), ...extra};
    for (const [key, value] of Object.entries(fields)) body.set(key, value);
    const response = await fetch(root.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
    let data;
    try { data = await response.json(); } catch { throw new Error('Your session expired. Refresh the page and sign in.'); }
    if (!response.ok || !data.ok) throw new Error(data.error || 'Passkey request failed.');
    return data;
  }
  function options(data) {
    const key = data.options.publicKey;
    key.challenge = decode(key.challenge);
    if (key.user) key.user.id = decode(key.user.id);
    for (const list of ['allowCredentials', 'excludeCredentials']) {
      if (key[list]) for (const credential of key[list]) credential.id = decode(credential.id);
    }
    return {publicKey: key};
  }
  async function run(operation) {
    if (!window.isSecureContext || !navigator.credentials || !window.PublicKeyCredential) throw new Error('Passkeys require HTTPS and a compatible browser.');
    const managing = root.dataset.mode === 'manage';
    if (operation === 'register') {
      const credential = await navigator.credentials.create(options(await call('register-options')));
      if (!credential) throw new Error('Passkey creation was canceled.');
      await call('register-verify', {credential: JSON.stringify({clientDataJSON: encode(credential.response.clientDataJSON), attestationObject: encode(credential.response.attestationObject), label: field('passkeyLabel')})});
      location.reload();
    } else if (!managing) {
      const credential = await navigator.credentials.get(options(await call('login-options')));
      if (!credential) throw new Error('Passkey sign-in was canceled.');
      const data = await call('login-verify', {credential: JSON.stringify({rawId: encode(credential.rawId), clientDataJSON: encode(credential.response.clientDataJSON), authenticatorData: encode(credential.response.authenticatorData), signature: encode(credential.response.signature), userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : ''})});
      location.assign(data.redirect);
    }
  }
  start.addEventListener('click', async () => {
    start.disabled = true;
    status.textContent = 'Follow the instructions in your device account picker.';
    try { await run(root.dataset.mode === 'manage' ? 'register' : 'login'); }
    catch (error) { status.textContent = error.message || 'Passkey operation canceled.'; }
    finally { start.disabled = false; }
  });
  root.querySelectorAll('[data-passkey-revoke]').forEach(button => button.addEventListener('click', async () => {
    button.disabled = true;
    try { await call('revoke', {passkey_id: button.dataset.passkeyRevoke}); location.reload(); }
    catch (error) { status.textContent = error.message; button.disabled = false; }
  }));
})();
