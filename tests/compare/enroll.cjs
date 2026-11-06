// Laravel-only checks for the seamless entry: the first scan of /music or /no-music creates the patient.
const BASE = process.argv[2] || 'http://127.0.0.1:8089';
class C { constructor() { this.cookie = ''; this.csrf = ''; }
  async raw(url, opts = {}) { const res = await fetch(BASE + url, { ...opts, headers: { ...(opts.headers || {}), Cookie: this.cookie }, redirect: 'manual' }); for (const c of res.headers.getSetCookie?.() || []) { const [pair] = c.split(';'); const [k] = pair.split('='); this.cookie = this.cookie.split('; ').filter((x) => x && !x.startsWith(k + '=')).concat(pair).join('; '); } return res; }
  async init() { this.csrf = (await (await this.raw('/api.php?action=session')).json()).csrf; return this; }
  async post(action, data, extra = {}) { const r = await this.raw('/api.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf, ...extra }, body: JSON.stringify(data) }); let b; try { b = await r.json(); } catch { b = null; } return { status: r.status, body: b }; }
  async get(qs, extra = {}) { const r = await this.raw('/api.php?' + qs, { headers: extra }); let b; try { b = await r.json(); } catch { b = null; } return { status: r.status, body: b }; } }
const results = []; const check = (n, ok, x = '') => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (x ? '  ' + x : '')); };
(async () => {
  const a = await new C().init(), b = await new C().init(), c = await new C().init();
  const m = await a.post('enroll', { entry: 'music' }); check('scan /music creates a patient and returns their token', m.status === 200 && /^[a-f0-9]{64}$/.test(m.body?.token || ''));
  const n = await b.post('enroll', { entry: 'no-music' }); check('scan /no-music creates another patient', n.status === 200 && n.body.token !== m.body.token);
  const bad = await c.post('enroll', { entry: 'elsewhere' }); check('unknown link name is refused', bad.status === 404, bad.body?.error);
  const mv = await a.get('action=patient', { 'X-Participant': m.body.token }); const nv = await b.get('action=patient', { 'X-Participant': n.body.token });
  check('music patient: first stage T0, music questions available, audio ready', mv.status === 200 && mv.body.participant.stage === 't0' && mv.body.participant.study_group === 'audio' && mv.body.audio_available === true);
  check('no-music patient: first stage T0, usual care', nv.body.participant.stage === 't0' && nv.body.participant.study_group === 'usual' && nv.body.audio_available === false);
  check('each patient gets their own code', mv.body.participant.code !== nv.body.participant.code && /^YM-[0-9A-F]{6}$/.test(mv.body.participant.code));
  // baseline goes straight to the listening session for the music patient (group already known)
  const sub = await a.post('save', { type: 't0', answers: { t0_anxiety: 4, t0_relaxation: 6 }, revision: 0, submit: true }, { 'X-Participant': m.body.token });
  const after = await a.get('action=patient', { 'X-Participant': m.body.token }); check('after baseline the music patient reaches the listening session', sub.status === 200 && after.body.participant.stage === 'session');
  // what the team sees
  const login = await new C().init(); const pw = require('fs').readFileSync('E:/youmind blog page/digital-assessment/private/LOCAL-LOGIN.txt', 'utf8').match(/Password: (.+)/)[1].trim();
  await login.post('login', { username: 'admin', password: pw });
  const list = await login.get('action=list'); const mine = list.body.participants.filter((p) => p.code === mv.body.participant.code || p.code === nv.body.participant.code);
  check('the team sees both new patients', mine.length === 2 && mine.every((p) => p.site === 'Self-enrolled (link)' && p.consent_confirmed === 0));
  const audit = await login.get('action=audit&id=' + mine.find((p) => p.study_group === 'audio').id); check('the sign-up is logged', audit.body.some((x) => x.action === 'patient_enrolled'));
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
