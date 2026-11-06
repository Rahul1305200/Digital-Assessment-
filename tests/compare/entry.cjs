// Laravel-only checks for the study-code entry (the first version has no /music or /no-music links).
const BASE = process.argv[2] || 'http://127.0.0.1:8089';
let cookie = ''; let csrf = '';
async function raw(url, opts = {}) {
  const res = await fetch(BASE + url, { ...opts, headers: { ...(opts.headers || {}), Cookie: cookie }, redirect: 'manual' });
  for (const c of res.headers.getSetCookie?.() || []) { const [pair] = c.split(';'); const [k] = pair.split('='); cookie = cookie.split('; ').filter((x) => x && !x.startsWith(k + '=')).concat(pair).join('; '); }
  return res;
}
async function enter(entry, code) {
  const r = await raw('/api.php?action=enter', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ entry, code }) });
  let body; try { body = await r.json(); } catch { body = null; } return { status: r.status, body };
}
const results = []; const check = (name, ok, extra = '') => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (extra ? '  ' + extra : '')); };
(async () => {
  for (const p of ['/music', '/no-music', '/music/', '/']) { const r = await raw(p); check(`page ${p} opens`, r.status === 200 && (await r.text()).includes('<base href="/">')); }
  csrf = (await (await raw('/api.php?action=session')).json()).csrf;
  const ok = await enter('music', 'ym-test-03'); check('music code on the music link (lower case typed)', ok.status === 200 && /^[a-f0-9]{64}$/.test(ok.body?.token || ''));
  const wrong = await enter('no-music', 'YM-TEST-03'); check('music code on the other link is refused', wrong.status === 409, wrong.body?.error);
  const un1 = await enter('music', 'YM-TEST-01'), un2 = await enter('no-music', 'YM-TEST-01'); check('unassigned patient can use either link', un1.status === 200 && un2.status === 200 && un1.body.token === un2.body.token);
  const bad = await enter('music', 'YM-NOPE00'); check('unknown code', bad.status === 404, bad.body?.error);
  const junk = await enter('music', "x'; DROP TABLE participants;--"); check('odd input is rejected safely', junk.status === 404);
  const badEntry = await enter('somewhere', 'YM-TEST-03'); check('unknown link name', badEntry.status === 404, badEntry.body?.error);
  // the token returned works for the patient screens
  const pr = await raw('/api.php?action=patient', { headers: { 'X-Participant': ok.body.token } }); const pj = await pr.json(); check('token opens the patient screens', pr.status === 200 && pj.participant.stage === 'session');
  // rate limit: wrong codes only
  let last; for (let i = 0; i < 12; i++) last = await enter('music', 'YM-WRONG' + i);
  check('too many wrong codes are blocked', last.status === 429, last.body?.error);
  const blocked = await enter('music', 'YM-TEST-03'); check('even a right code waits while blocked', blocked.status === 429);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
