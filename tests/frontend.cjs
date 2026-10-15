const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/clerk-auth.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
async function fixture({missingSdk = false, loadError = false, deny = false} = {}) {
  const calls = {mount: [], listener: [], fetch: 0, redirect: [], logs: []};
  const error = {hidden: true, textContent: ''};
  const cfg = {portalUrl: 'https://local.example/portal/', proxyUrl: 'https://local.example/__clerk', restUrl: 'https://local.example/wp-json/clerk-wp-sync/v1/session-login', nonce: 'fixture', genericError: 'Could not sign in.'};
  let load;
  const Clerk = {
    user: null, session: {getToken: async () => 'fixture.invalid.jwt'},
    load: async opts => { calls.load = opts; if (loadError) throw new Error('fixture failure'); },
    mountSignIn: (el, opts) => calls.mount.push(opts),
    addListener: fn => calls.listener.push(fn),
    signOut: async () => { Clerk.user = null; },
  };
  const window = {
    ClerkWPAuth: cfg, ...(missingSdk ? {} : {Clerk}),
    addEventListener: (name, fn) => { if (name === 'load') load = fn; },
    setTimeout: fn => queueMicrotask(fn),
    location: {search: '', pathname: '/portal/', assign: value => calls.redirect.push(value)},
    history: {replaceState() {}}, console: {error: (...args) => calls.logs.push(args)},
  };
  const fetch = async (url, options) => {
    calls.fetch++; calls.request = {url, options};
    await tick();
    return {ok: !deny, json: async () => deny ? {code: 'registration_email_denied', message: 'Not allowed'} : {ok: true, redirect: cfg.portalUrl}};
  };
  const document = {getElementById: id => id === 'clerk-signin-error' ? error : id === 'clerk-signin' ? {} : null};
  vm.runInNewContext(source, {window, document, fetch, URLSearchParams, console: window.console});
  await load();
  return {calls, Clerk, error, cfg};
}
(async () => {
  const f = await fixture();
  assert.equal(f.calls.load.proxyUrl, f.cfg.proxyUrl);
  assert.equal(f.calls.mount.length, 1);
  assert.equal(f.calls.mount[0].forceRedirectUrl, f.cfg.portalUrl);
  assert.equal('afterSignInUrl' in f.calls.mount[0], false);
  assert.equal('afterSignUpUrl' in f.calls.mount[0], false);
  f.calls.listener[0]({user: {}, session: null});
  await tick(); assert.equal(f.calls.fetch, 0);
  f.calls.listener[0]({user: {}, session: {}});
  f.calls.listener[0]({user: {}, session: {}});
  await tick(); await tick();
  assert.equal(f.calls.fetch, 1, 'duplicate auth updates have one bridge request');
  assert.equal(f.calls.request.url, f.cfg.restUrl);
  assert.equal(f.calls.request.options.credentials, 'same-origin');
  assert.equal(f.calls.redirect[0], f.cfg.portalUrl);
  for (const opts of [{missingSdk: true}, {loadError: true}]) {
    const f = await fixture(opts);
    assert.equal(f.error.hidden, false);
    assert.match(f.error.textContent, /could not load/);
    assert.equal(f.calls.fetch, 0);
  }
  const denied = await fixture({deny: true});
  denied.Clerk.user = {};
  denied.calls.listener[0]({user: {}, session: {}});
  await tick(); await tick();
  assert.equal(denied.calls.redirect.length, 0);
  assert.equal(denied.error.textContent, 'Not allowed');
  assert.equal(denied.calls.mount.length, 2);
  assert.equal(denied.calls.listener.length, 1, 'remount does not register another listener');
  console.log('Frontend bridge regression checks passed; no browser or Clerk network requests made.');
})().catch(error => { console.error(error); process.exitCode = 1; });
