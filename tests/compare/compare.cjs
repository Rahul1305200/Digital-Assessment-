// Runs the same API steps against the first version (plain PHP) and the Laravel version,
// normalises ids, codes, tokens and timestamps, then diffs the two transcripts.
//   node tests/compare/compare.cjs [oldBase] [newBase]
const fs = require('fs');
const path = require('path');

const OLD = process.argv[2] || 'http://127.0.0.1:8088';
const NEW = process.argv[3] || 'http://127.0.0.1:8089';
const ADMIN_PW = fs.readFileSync('E:/youmind blog page/digital-assessment/private/LOCAL-LOGIN.txt', 'utf8').match(/Password: (.+)/)[1].trim();

class Client {
  constructor(base) { this.base = base; this.cookies = {}; this.csrf = null; }
  async fetch(url, opts = {}) {
    const headers = { ...(opts.headers || {}) };
    const jar = Object.entries(this.cookies).map(([k, v]) => `${k}=${v}`).join('; ');
    if (jar) headers.Cookie = jar;
    const res = await fetch(this.base + url, { ...opts, headers, redirect: 'manual' });
    for (const c of res.headers.getSetCookie?.() || []) {
      const [pair] = c.split(';'); const i = pair.indexOf('=');
      this.cookies[pair.slice(0, i)] = pair.slice(i + 1);
    }
    return res;
  }
  async session() { const r = await this.fetch('/api.php?action=session'); const j = await r.json(); this.csrf = j.csrf; return j; }
  async call(action, data, extra = {}) {
    if (!this.csrf) await this.session();
    const headers = { 'X-CSRF-Token': this.csrf, 'Content-Type': 'application/json', ...(extra.headers || {}) };
    const res = await this.fetch('/api.php?action=' + action, { method: 'POST', headers, body: JSON.stringify(data ?? {}) });
    const text = await res.text();
    let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: res.status, body, headers: res.headers };
  }
  async upload(fields, file) {
    if (!this.csrf) await this.session();
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) form.append(k, v);
    if (file) form.append('audio', new Blob([file.buffer], { type: file.type }), file.name);
    const res = await this.fetch('/api.php?action=upload', { method: 'POST', headers: { 'X-CSRF-Token': this.csrf }, body: form });
    const text = await res.text();
    let body; try { body = JSON.parse(text); } catch { body = text; }
    return { status: res.status, body };
  }
  async get(qs, extra = {}) {
    const res = await this.fetch('/api.php?' + qs, { headers: extra.headers || {} });
    const buf = Buffer.from(await res.arrayBuffer());
    let body = buf.toString('utf8'); try { body = JSON.parse(body); } catch { /* keep text */ }
    return { status: res.status, body, headers: res.headers, bytes: buf.length };
  }
}

function makeNorm(marker) {
  return (value) => {
    let s = typeof value === 'string' ? value : JSON.stringify(value);
    s = s.split(marker).join('<SITE>');
    s = s.replace(/[a-f0-9]{64}/g, '<TOKEN>')
      .replace(/[a-f0-9]{48}/g, '<CSRF>')
      .replace(/YM-[0-9A-F]{6}/g, '<CODE>')
      .replace(/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?/g, '<TS>')
      .replace(/"(id|participant_id)":\d+/g, '"$1":<N>')
      .replace(/20\d\d-\d\d-\d\d/g, '<DATE>');
    return s;
  };
}

async function run(base) {
  const marker = 'CMP-' + Math.random().toString(36).slice(2, 8).toUpperCase();
  const norm = makeNorm(marker);
  const log = [];
  const rec = (step, r, extra = '') => log.push({ step, status: r.status, body: norm(r.body), extra });

  const anon = new Client(base);
  const s0 = await anon.session();
  log.push({ step: 'session (anonymous)', status: 200, body: norm(s0) });
  rec('list without sign-in', await anon.get('action=list'));
  rec('export without sign-in', await anon.get('action=export'));
  rec('create without csrf', await (async () => { const res = await anon.fetch('/api.php?action=create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); return { status: res.status, body: await res.text() }; })());
  rec('write action by GET', await anon.get('action=create'));
  rec('unknown action', await anon.get('action=nope'));
  rec('login wrong password', await anon.call('login', { username: 'admin', password: 'wrong-password' }));

  const staff = new Client(base);
  await staff.session();
  rec('login ok', await staff.call('login', { username: 'admin', password: ADMIN_PW }));
  rec('session after login', { status: 200, body: await staff.session() });

  // read-only views of the data that existed before the run
  const list0 = await staff.get('action=list');
  rec('list (existing data)', list0);

  // create participants
  rec('create without consent', await staff.call('create', { site: marker, consent: false }));
  rec('create without site', await staff.call('create', { site: '', consent: true }));
  rec('create A', await staff.call('create', { site: marker, consent: true, sleep: true }));
  rec('create B', await staff.call('create', { site: marker, consent: true, sleep: false }));
  const mine = (await staff.get('action=list')).body.participants.filter((p) => p.site === marker).sort((a, b) => a.id - b.id);
  const [A, B] = mine;
  const aud = (await staff.get('action=list')).body.audio[0];
  rec('audio library', { status: 200, body: { count: (await staff.get('action=list')).body.audio.length } });

  // patient A flow (music group)
  const pa = new Client(base); await pa.session();
  const pcall = (c, p, action, data) => c.call(action, data, { headers: { 'X-Participant': p.token } });
  rec('patient view (unassigned)', await (async () => { const r = await pa.get('action=patient', { headers: { 'X-Participant': A.token } }); return r; })());
  rec('patient bad token', await pa.get('action=patient', { headers: { 'X-Participant': 'f'.repeat(64) } }));
  rec('patient save out-of-range', await pcall(pa, A, 'save', { type: 't0', answers: { t0_anxiety: 11 }, revision: 0 }));
  rec('patient save wrong revision', await pcall(pa, A, 'save', { type: 't0', answers: { t0_anxiety: 3 }, revision: 5 }));
  rec('patient save partial', await pcall(pa, A, 'save', { type: 't0', answers: { t0_anxiety: 3 }, revision: 0 }));
  rec('patient submit with missing answers', await pcall(pa, A, 'save', { type: 't0', answers: { t0_anxiety: 3 }, revision: 1, submit: true }));
  rec('patient submit T0', await pcall(pa, A, 'save', { type: 't0', answers: { t0_anxiety: 3, t0_relaxation: 6 }, revision: 1, submit: true }));
  rec('patient heartbeat too early', await pcall(pa, A, 'heartbeat', { seconds: 1 }));
  rec('manual group without recording id (uses the one recording)', await staff.call('manual_allocate', { id: A.id, group: 'audio' }));
  rec('manual group again (locked)', await staff.call('manual_allocate', { id: A.id, group: 'usual' }));
  rec('patient view (music, session)', await pa.get('action=patient', { headers: { 'X-Participant': A.token } }));
  rec('patient heartbeat first', await pcall(pa, A, 'heartbeat', { seconds: 0 }));
  await new Promise((r) => setTimeout(r, 2200));
  rec('patient heartbeat second', await pcall(pa, A, 'heartbeat', { seconds: 2 }));
  rec('patient heartbeat invalid', await pcall(pa, A, 'heartbeat', { seconds: 99 }));
  // audio file with and without Range
  const full = await pa.get('action=audio', { headers: { 'X-Participant': A.token } });
  log.push({ step: 'audio full', status: full.status, body: `${full.headers.get('content-type')} ${full.headers.get('accept-ranges')} len=${full.headers.get('content-length')} got=${full.bytes}` });
  const part = await pa.get('action=audio', { headers: { 'X-Participant': A.token, Range: 'bytes=10-109' } });
  log.push({ step: 'audio range', status: part.status, body: `${part.headers.get('content-range')} len=${part.headers.get('content-length')} got=${part.bytes}` });
  const bad = await pa.get('action=audio', { headers: { 'X-Participant': A.token, Range: 'bytes=999999999-' } });
  log.push({ step: 'audio bad range', status: bad.status, body: `got=${bad.bytes}` });
  rec('patient session_end', await pcall(pa, A, 'session_end', { reason: 'Participant continued / session stopped' }));
  rec('patient T2 before release', await pcall(pa, A, 'save', { type: 't2', answers: { t2_anxiety: 2 }, revision: 0 }));
  rec('staff advance (open T2)', await staff.call('advance', { id: A.id }));
  rec('patient save T2 (music questions)', await pcall(pa, A, 'save', { type: 't2', answers: { t2_anxiety: 2, t2_relaxation: 7, t2_listening_experience: 'Pleasant', t2_unwanted_effects: 'No' }, revision: 0, submit: true }));
  rec('staff change group', await staff.call('change_group', { id: A.id, group: 'usual', reason: 'Comparison test' }));
  rec('staff change group (same group)', await staff.call('change_group', { id: A.id, group: 'usual', reason: 'Comparison test' }));
  rec('staff change group back', await staff.call('change_group', { id: A.id, group: 'audio', reason: 'Comparison test' }));
  rec('staff advance (open evening)', await staff.call('advance', { id: A.id }));
  rec('operations bad delay', await staff.call('operations', { id: A.id, record: { delay_status: 'Yes' }, revision: 0 }));
  rec('operations save', await staff.call('operations', { id: A.id, record: { delay_status: 'No', interruption_reason: 'Physician became available', technical_issue: '' }, revision: 0 }));
  rec('operations stale revision', await staff.call('operations', { id: A.id, record: { delay_status: 'No' }, revision: 0 }));
  rec('close assessment', await staff.call('close_assessment', { id: A.id, reason: 'Technical issue' }));
  rec('close assessment (bad reason)', await staff.call('close_assessment', { id: A.id, reason: 'because' }));

  // sleep audio at home is logged like the first session
  rec('sleep heartbeat first', await pcall(pa, A, 'heartbeat', { seconds: 0, kind: 'sleep' }));
  await new Promise((r) => setTimeout(r, 2200));
  rec('sleep heartbeat second', await pcall(pa, A, 'heartbeat', { seconds: 2, kind: 'sleep' }));
  const sleepAudio = await pa.get('action=audio&kind=sleep', { headers: { 'X-Participant': A.token } });
  log.push({ step: 'sleep audio', status: sleepAudio.status, body: `${sleepAudio.headers.get('content-type')} got=${sleepAudio.bytes}` });
  rec('patient view (sleep audio shown)', await pa.get('action=patient', { headers: { 'X-Participant': A.token } }));

  // audio upload replaces the one recording (same content and title, so the data stays as it was)
  const current = (await staff.get('action=list')).body.audio[0];
  const wavPath = require('fs').readdirSync('E:/youmind blog page/digital-assessment-laravel/storage/app/private/audio')[0];
  const wav = require('fs').readFileSync('E:/youmind blog page/digital-assessment-laravel/storage/app/private/audio/' + wavPath);
  rec('upload without file', await staff.upload({ title: current.title }, null));
  rec('upload wrong type', await staff.upload({ title: current.title }, { buffer: Buffer.from('not audio at all'), type: 'text/plain', name: 'x.txt' }));
  rec('upload replaces recording', await staff.upload({ title: current.title, description: current.description || '' }, { buffer: wav, type: 'audio/wav', name: 'same.wav' }));
  rec('audio library after upload', { status: 200, body: (await staff.get('action=list')).body.audio.map((x) => ({ title: x.title, description: x.description })) });
  const afterUpload = await pa.get('action=audio&kind=sleep', { headers: { 'X-Participant': A.token } });
  log.push({ step: 'sleep audio after replace', status: afterUpload.status, body: `got=${afterUpload.bytes}` });

  // randomized allocation from an approved sequence
  rec('sequence import unbalanced', await staff.call('sequence_import', { site: marker, reference: marker, groups: ['audio', 'audio'], approved: true }));
  rec('sequence import not approved', await staff.call('sequence_import', { site: marker, reference: marker, groups: ['usual', 'audio'], approved: false }));
  rec('sequence import', await staff.call('sequence_import', { site: marker, reference: marker, groups: ['usual', 'audio'], approved: true }));
  rec('sequence import again (blocked)', await staff.call('sequence_import', { site: marker, reference: marker, groups: ['usual', 'audio'], approved: true }));
  rec('create C', await staff.call('create', { site: marker, consent: true }));
  const C = (await staff.get('action=list')).body.participants.filter((p) => p.site === marker && p.code !== A.code && p.code !== B.code)[0];
  const pc = new Client(base); await pc.session();
  rec('allocate before baseline', await staff.call('allocate', { id: C.id }));
  rec('C submit T0', await pcall(pc, C, 'save', { type: 't0', answers: { t0_anxiety: 5, t0_relaxation: 5 }, revision: 0, submit: true }));
  rec('allocate from sequence', await staff.call('allocate', { id: C.id }));
  rec('C view after allocation', await pc.get('action=patient', { headers: { 'X-Participant': C.token } }));

  // staff password change and sign-out
  const pwUser = 'cmp.pw' + marker.toLowerCase().replace(/[^a-z0-9]/g, '');
  rec('create password test user', await staff.call('user_create', { username: pwUser, password: 'FirstPassword-123', role: 'representative' }));
  const pw = new Client(base); await pw.session();
  rec('pw user login', await pw.call('login', { username: pwUser, password: 'FirstPassword-123' }));
  rec('password too short', await pw.call('password', { old: 'FirstPassword-123', new: 'short' }));
  rec('password change', await pw.call('password', { old: 'FirstPassword-123', new: 'SecondPassword-456' }));
  rec('still signed in after change', await pw.get('action=list'));
  rec('sign out', await pw.call('logout', {}));
  rec('after sign out', await pw.get('action=list'));
  const pw2 = new Client(base); await pw2.session();
  rec('old password rejected', await pw2.call('login', { username: pwUser, password: 'FirstPassword-123' }));
  rec('new password accepted', await pw2.call('login', { username: pwUser, password: 'SecondPassword-456' }));
  const pwId = (await staff.get('action=users_list')).body.users.find((u) => u.username === pwUser).id;
  rec('delete password test user', await staff.call('user_delete', { id: pwId }));

  // patient B (no music)
  const pb = new Client(base); await pb.session();
  rec('B submit T0', await pcall(pb, B, 'save', { type: 't0', answers: { t0_anxiety: 7, t0_relaxation: 2 }, revision: 0, submit: true }));
  rec('B manual group usual', await staff.call('manual_allocate', { id: B.id, group: 'usual' }));
  rec('B advance (open T2)', await staff.call('advance', { id: B.id }));
  rec('B decline T2', await pcall(pb, B, 'save', { type: 't2', answers: {}, revision: 0, decline: true }));
  rec('B advance (cannot yet)', await staff.call('advance', { id: B.id }));

  // roles
  const un = 'cmp.' + marker.toLowerCase().replace(/[^a-z0-9]/g, '');
  rec('users_list', { status: 200, body: { count: (await staff.get('action=users_list')).body.users.length } });
  rec('user_create short password', await staff.call('user_create', { username: un, password: 'short', role: 'viewer' }));
  rec('user_create bad username', await staff.call('user_create', { username: 'A B', password: 'LongEnough-12345', role: 'viewer' }));
  rec('user_create viewer', await staff.call('user_create', { username: un + 'v', password: 'ViewerPass-123456', role: 'viewer' }));
  rec('user_create rep', await staff.call('user_create', { username: un + 'r', password: 'RepPass-12345678', role: 'representative' }));
  rec('user_create duplicate', await staff.call('user_create', { username: un + 'r', password: 'RepPass-12345678', role: 'representative' }));
  rec('demote last admin', await staff.call('user_update', { id: 1, role: 'viewer' }));
  rec('delete self', await staff.call('user_delete', { id: 1 }));
  const viewer = new Client(base); await viewer.session(); rec('viewer login', await viewer.call('login', { username: un + 'v', password: 'ViewerPass-123456' }));
  rec('viewer list role', { status: 200, body: { role: (await viewer.get('action=list')).body.role } });
  rec('viewer create', await viewer.call('create', { site: marker, consent: true }));
  rec('viewer advance', await viewer.call('advance', { id: A.id }));
  rec('viewer users_list', await viewer.get('action=users_list'));
  const rep = new Client(base); await rep.session(); rec('rep login', await rep.call('login', { username: un + 'r', password: 'RepPass-12345678' }));
  rec('rep users_list', await rep.get('action=users_list'));
  rec('rep upload (admin only)', await rep.call('upload', {}));
  rec('rep change own password (wrong old)', await rep.call('password', { old: 'nope', new: 'AnotherLong-12345' }));
  const ids = (await staff.get('action=users_list')).body.users.filter((u) => u.username.startsWith(un));
  for (const u of ids) rec('delete test user', await staff.call('user_delete', { id: u.id }));
  rec('removed user session is rejected', await viewer.get('action=list'));

  // audit + exports
  rec('audit of A', await staff.get('action=audit&id=' + A.id));
  const csv = await staff.get('action=export');
  log.push({ step: 'export csv (headers)', status: csv.status, body: `${csv.headers.get('content-type')} | ${norm(csv.headers.get('content-disposition') || '')} | ${csv.headers.get('cache-control')}` });
  log.push({ step: 'export csv (all rows)', status: csv.status, body: norm(csv.body) });
  const ops = await staff.get('action=export_operations');
  log.push({ step: 'export operations csv', status: ops.status, body: norm(ops.body) });

  // final state of my participants
  const final = (await staff.get('action=list')).body.participants.filter((p) => p.site === marker);
  rec('final state of test participants (admin view)', { status: 200, body: final });

  // clean up everything this run created so the data stays tidy
  log.push({ step: 'marker', status: 0, body: marker });
  return { log, marker };
}

(async () => {
  const outDir = path.join(__dirname, 'results'); fs.mkdirSync(outDir, { recursive: true });
  const [a, b] = [await run(OLD), await run(NEW)];
  const strip = (r) => r.log.filter((x) => x.step !== 'marker');
  const A = strip(a), B = strip(b);
  fs.writeFileSync(path.join(outDir, 'old.json'), JSON.stringify(A, null, 1));
  fs.writeFileSync(path.join(outDir, 'new.json'), JSON.stringify(B, null, 1));
  let diffs = 0;
  const n = Math.max(A.length, B.length);
  for (let i = 0; i < n; i++) {
    const x = A[i], y = B[i];
    if (!x || !y || x.step !== y.step || x.status !== y.status || x.body !== y.body) {
      diffs++;
      console.log(`\nDIFF #${diffs}: ${x?.step || y?.step}`);
      console.log('  old:', x?.status, String(x?.body).slice(0, 400));
      console.log('  new:', y?.status, String(y?.body).slice(0, 400));
    }
  }
  console.log(`\n${A.length} steps compared, ${diffs} difference(s).  Markers: old=${a.marker} new=${b.marker}`);
  fs.writeFileSync(path.join(outDir, 'markers.json'), JSON.stringify({ old: a.marker, new: b.marker }));
  process.exit(diffs ? 1 : 0);
})().catch((e) => { console.error('compare failed:', e); process.exit(2); });
