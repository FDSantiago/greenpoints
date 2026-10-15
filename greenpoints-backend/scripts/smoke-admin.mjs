// End-to-end smoke test for the admin API: users, groups, categories, rewards, point adjustments, impact reports.
// Run from the Laravel project root while `php artisan serve --host=0.0.0.0` is running:
//     node scripts/smoke-admin.mjs
// Needs the seeded accounts (php artisan db:seed) and the newest migration (php artisan migrate).
// Uses 4 logins; login is throttled to 5/min per IP, so wait a minute after running another smoke script.
// It leaves a few harmless "Smoke ..." rows behind; php artisan migrate:fresh --seed wipes them.

const BASE = process.env.API_URL ?? 'http://localhost:8000/api/v1';
let failures = 0;

function check(cond, label, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);
  if (!cond) {
    failures++;
    if (detail !== undefined) console.log('      ->', JSON.stringify(detail).slice(0, 700));
  }
}

async function call(method, path, { token, body, headers = {} } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  try { json = await res.json(); } catch { /* 204 or non-JSON */ }
  return { status: res.status, json };
}

async function csv(path, token) {
  const res = await fetch(BASE + path, { headers: { Accept: 'text/csv', Authorization: `Bearer ${token}` } });
  return { status: res.status, type: res.headers.get('content-type') ?? '', text: await res.text() };
}

async function login(email, password = 'password') {
  const r = await call('POST', '/auth/login', { body: { email, password } });
  if (r.status === 429) {
    console.log('Login is throttled (5/min). Wait 60 seconds and run again.');
    process.exit(2);
  }
  return r;
}

async function loginOrExit(email) {
  const r = await login(email);
  if (r.status !== 200) {
    console.log(`Cannot log in as ${email} (HTTP ${r.status}). Did you run "php artisan db:seed" and "php artisan migrate"?`, r.json);
    process.exit(2);
  }
  return r.json.token;
}

async function main() {
  const stamp = Date.now();
  const admin = await loginOrExit('admin@greenpoints.test');
  const staff = await loginOrExit('staff@greenpoints.test');
  const adminId = (await call('GET', '/me', { token: admin })).json.data.id;

  // A fresh student. The extra role=admin field is a mass-assignment attack and must be ignored.
  const reg = await call('POST', '/auth/register', {
    body: {
      name: 'Smoke Student', email: `smoke+admin${stamp}@greenpoints.test`,
      password: 'password123', password_confirmation: 'password123', role: 'admin',
    },
  });
  check(reg.status === 201, 'register a test student', reg);
  check(reg.json?.user?.role === 'student', 'registering with role=admin still creates a student', reg.json);
  const student = reg.json.token;
  const studentId = reg.json.user.id;

  // ---- permissions ---------------------------------------------------------------------------
  check((await call('GET', '/admin/users')).status === 401, 'no token -> 401', null);
  check((await call('GET', '/admin/users', { token: student })).status === 403, 'student is blocked from /admin (403)', null);
  check((await call('GET', '/admin/users', { token: staff })).status === 403, 'staff is blocked from /admin (403)', null);
  const users0 = await call('GET', '/admin/users', { token: admin });
  check(users0.status === 200 && users0.json.data.length >= 1, 'admin can list users', users0);

  // ---- give the student some verified waste (used by later checks) ---------------------------
  const cats = await call('GET', '/waste-categories', { token: student });
  const plastic = cats.json.data.find((c) => c.name === 'Plastic');
  const sub = await call('POST', '/waste-submissions', {
    token: student,
    body: { items: [{ category_id: plastic.id, weight_kg: 2, waste_condition: 'clean' }] },
  });
  const ver = await call('POST', `/staff/submissions/${sub.json.data.id}/verify`, { token: staff });
  const P = ver.json.data.points_awarded;
  const month = sub.json.data.submitted_at.slice(0, 7);
  check(ver.status === 200 && P > 0, `student earned ${P} points from 2 kg of plastic`, ver);

  // ---- groups --------------------------------------------------------------------------------
  let gname = `Smoke-${stamp}`;
  const g = await call('POST', '/admin/groups', { token: admin, body: { name: gname, type: 'zone' } });
  check(g.status === 201 && g.json.data.type === 'zone', 'create a group', g);
  const gid = g.json.data.id;
  check((await call('POST', '/admin/groups', { token: admin, body: { name: gname } })).status === 422, 'duplicate group name is rejected (422)', null);
  check((await call('POST', '/admin/groups', { token: admin, body: { name: 'x', type: 'nope' } })).status === 422, 'invalid group type is rejected (422)', null);
  gname = `${gname}-b`;
  const gu = await call('PATCH', `/admin/groups/${gid}`, { token: admin, body: { name: gname, type: 'class' } });
  check(gu.status === 200 && gu.json.data.name === gname && gu.json.data.type === 'class', 'rename a group', gu);
  const gl = await call('GET', '/admin/groups', { token: admin });
  check(gl.json.data.some((x) => x.id === gid && x.members_count === 0), 'group list shows members_count', gl.json);

  const moved = await call('PATCH', `/admin/users/${studentId}`, { token: admin, body: { group_id: gid } });
  check(moved.status === 200 && moved.json.data.group?.id === gid, 'admin moves the student into the group', moved);
  const gs = await call('GET', `/admin/groups/${gid}`, { token: admin });
  check(gs.json.data.members_count === 1, 'group now has 1 member', gs.json);
  check((await call('DELETE', `/admin/groups/${gid}`, { token: admin })).status === 409, 'a group with members cannot be deleted (409)', null);

  // ---- users ---------------------------------------------------------------------------------
  const email = `smoke+new${stamp}@greenpoints.test`;
  const created = await call('POST', '/admin/users', {
    token: admin, body: { name: 'Smoke Staffer', email, password: 'password123', role: 'staff' },
  });
  check(created.status === 201 && created.json.data.role === 'staff' && created.json.data.is_active === true, 'admin creates a staff user', created);
  const newId = created.json.data.id;
  check((await call('POST', '/admin/users', { token: admin, body: { name: 'Dup', email, password: 'password123', role: 'student' } })).status === 422, 'duplicate email is rejected (422)', null);
  check((await call('POST', '/admin/users', { token: admin, body: { name: 'Weak', email: `w${email}`, password: 'short', role: 'student' } })).status === 422, 'weak password is rejected (422)', null);
  check((await call('POST', '/admin/users', { token: admin, body: { name: 'Bad', email: `b${email}`, password: 'password123', role: 'owner' } })).status === 422, 'unknown role is rejected (422)', null);

  const byQ = await call('GET', `/admin/users?q=${encodeURIComponent(email)}`, { token: admin });
  check(byQ.status === 200 && byQ.json.data.length === 1 && byQ.json.data[0].id === newId, 'search by email finds exactly that user', byQ);
  const byGroup = await call('GET', `/admin/users?group_id=${gid}`, { token: admin });
  check(byGroup.json.data.some((u) => u.id === studentId), 'filter by group works', byGroup.json);
  const byRole = await call('GET', '/admin/users?role=staff', { token: admin });
  check(byRole.json.data.some((u) => u.id === newId), 'filter by role works', byRole.json);
  const showStudent = await call('GET', `/admin/users/${studentId}`, { token: admin });
  check(showStudent.json.data.points_balance === P, 'user detail shows points_balance', showStudent.json);
  check((await call('GET', '/admin/users/999999', { token: admin })).status === 404, 'unknown user is 404', null);

  check((await call('PATCH', `/admin/users/${adminId}`, { token: admin, body: { role: 'student' } })).status === 422, "an admin can't change their own role (422)", null);
  check((await call('PATCH', `/admin/users/${adminId}`, { token: admin, body: { is_active: false } })).status === 422, "an admin can't deactivate themselves (422)", null);
  check((await call('DELETE', `/admin/users/${adminId}`, { token: admin })).status === 422, "an admin can't delete themselves (422)", null);

  const upd = await call('PATCH', `/admin/users/${newId}`, { token: admin, body: { name: 'Smoke Renamed', role: 'student' } });
  check(upd.status === 200 && upd.json.data.name === 'Smoke Renamed' && upd.json.data.role === 'student', 'admin edits name and role', upd);

  // deactivation: tokens die, login blocked, reactivation works
  const li = await login(email, 'password123');
  check(li.status === 200, 'the new user can log in', li);
  const tNew = li.json.token;
  check((await call('GET', '/me', { token: tNew })).status === 200, 'their token works', null);
  const deact = await call('PATCH', `/admin/users/${newId}`, { token: admin, body: { is_active: false } });
  check(deact.status === 200 && deact.json.data.is_active === false, 'admin deactivates the user', deact);
  check((await call('GET', '/me', { token: tNew })).status === 401, 'their existing token stops working (401)', null);
  const blocked = await login(email, 'password123');
  check(blocked.status === 403, 'a deactivated user cannot log in (403)', blocked);
  const react = await call('PATCH', `/admin/users/${newId}`, { token: admin, body: { is_active: true } });
  check(react.status === 200 && react.json.data.is_active === true, 'admin reactivates the user', react);

  check((await call('DELETE', `/admin/users/${newId}`, { token: admin })).status === 204, 'a user without activity can be deleted (204)', null);
  check((await call('GET', `/admin/users/${newId}`, { token: admin })).status === 404, 'the deleted user is gone (404)', null);
  check((await call('DELETE', `/admin/users/${studentId}`, { token: admin })).status === 409, 'a user with activity cannot be deleted (409)', null);

  // ---- point adjustments ---------------------------------------------------------------------
  const plus = await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: 50, note: 'smoke bonus' } });
  check(plus.status === 201 && plus.json.balance === P + 50 && plus.json.data.type === 'adjustment', 'positive adjustment works', plus);
  check(plus.json?.data?.note?.includes('Admin adjustment'), 'adjustment note records who did it', plus.json);
  check((await call('GET', '/wallet', { token: student })).json.data.balance === P + 50, "the student's wallet reflects it", null);
  check((await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: -(P + 51), note: 'too much' } })).status === 422, 'an adjustment cannot make the balance negative (422)', null);
  const minus = await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: -20, note: 'correction' } });
  check(minus.status === 201 && minus.json.balance === P + 30, 'negative adjustment works', minus);
  check((await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: 0, note: 'zero' } })).status === 422, 'zero adjustment is rejected (422)', null);
  check((await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: 5 } })).status === 422, 'a note is required (422)', null);
  check((await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: staff, body: { points: 5, note: 'x' } })).status === 403, 'staff cannot adjust points (403)', null);
  const ledger = await call('GET', `/admin/users/${studentId}/transactions`, { token: admin });
  check(ledger.status === 200 && ledger.json.data.filter((t) => t.type === 'adjustment').length === 2, "admin sees the user's ledger with both adjustments", ledger);

  // ---- waste categories ----------------------------------------------------------------------
  const cname = `SmokeCat-${stamp}`;
  const cc = await call('POST', '/admin/waste-categories', { token: admin, body: { name: cname, points_per_kg: 3.5 } });
  check(cc.status === 201 && cc.json.data.points_per_kg === 3.5 && cc.json.data.is_recyclable === true, 'create a waste category', cc);
  const cid = cc.json.data.id;
  check((await call('POST', '/admin/waste-categories', { token: admin, body: { name: cname, points_per_kg: 1 } })).status === 422, 'duplicate category is rejected (422)', null);
  check((await call('POST', '/admin/waste-categories', { token: admin, body: { name: `${cname}2`, points_per_kg: -1 } })).status === 422, 'negative rate is rejected (422)', null);
  const cu = await call('PATCH', `/admin/waste-categories/${cid}`, { token: admin, body: { points_per_kg: 4 } });
  check(cu.status === 200 && cu.json.data.points_per_kg === 4, 'update the rate', cu);
  check((await call('GET', '/waste-categories', { token: student })).json.data.some((c) => c.name === cname), 'students see the new category', null);
  await call('PATCH', `/admin/waste-categories/${cid}`, { token: admin, body: { is_active: false } });
  check(!(await call('GET', '/waste-categories', { token: student })).json.data.some((c) => c.name === cname), 'an inactive category is hidden from students', null);
  check((await call('GET', '/admin/waste-categories', { token: admin })).json.data.some((c) => c.id === cid && c.is_active === false), 'admins still see inactive categories', null);
  check((await call('DELETE', `/admin/waste-categories/${cid}`, { token: admin })).status === 204, 'an unused category can be deleted (204)', null);
  check((await call('DELETE', `/admin/waste-categories/${plastic.id}`, { token: admin })).status === 409, 'a category used by waste logs cannot be deleted (409)', null);

  // ---- rewards -------------------------------------------------------------------------------
  const rname = `SmokeReward-${stamp}`;
  const rc = await call('POST', '/admin/rewards', { token: admin, body: { name: rname, description: 'smoke', points_cost: 5, stock_qty: 2 } });
  check(rc.status === 201 && rc.json.data.stock_qty === 2, 'create a reward', rc);
  const rid = rc.json.data.id;
  check((await call('POST', '/admin/rewards', { token: admin, body: { name: `${rname}b`, points_cost: 5, stock_qty: -1 } })).status === 422, 'negative stock is rejected (422)', null);
  check((await call('POST', '/admin/rewards', { token: admin, body: { name: `${rname}c`, points_cost: 0, stock_qty: 1 } })).status === 422, 'zero cost is rejected (422)', null);
  check((await call('POST', '/admin/rewards', { token: admin, body: { name: `${rname}d`, points_cost: 5, stock_qty: 1, image_url: 'nope' } })).status === 422, 'invalid image_url is rejected (422)', null);
  check((await call('GET', '/rewards', { token: student })).json.data.some((r) => r.name === rname), 'students see the new reward', null);

  const red = await call('POST', `/rewards/${rid}/redeem`, { token: student });
  check(red.status === 201 && red.json.balance_after === P + 30 - 5, 'the student redeems it', red);
  const rs = await call('GET', `/admin/rewards/${rid}`, { token: admin });
  check(rs.json.data.redemptions_count === 1 && rs.json.data.stock_qty === 1, 'admin sees redemptions_count and reduced stock', rs.json);
  check((await call('DELETE', `/admin/rewards/${rid}`, { token: admin })).status === 409, 'a redeemed reward cannot be deleted (409)', null);
  await call('PATCH', `/admin/rewards/${rid}`, { token: admin, body: { is_active: false } });
  check(!(await call('GET', '/rewards', { token: student })).json.data.some((r) => r.name === rname), 'an inactive reward is hidden from students', null);
  check((await call('POST', `/rewards/${rid}/redeem`, { token: student })).status === 404, 'an inactive reward cannot be redeemed (404)', null);
  const ru = await call('PATCH', `/admin/rewards/${rid}`, { token: admin, body: { stock_qty: 10, points_cost: 6 } });
  check(ru.status === 200 && ru.json.data.stock_qty === 10 && ru.json.data.points_cost === 6, 'update stock and price', ru);

  // ---- impact reports ------------------------------------------------------------------------
  const live = await call('GET', `/admin/reports/impact?month=${month}`, { token: admin });
  const row = live.json?.data?.groups?.find((x) => x.group_id === gid);
  check(live.status === 200 && row !== undefined, 'live impact report lists the group', live);
  check(row?.students === 1 && row?.total_kg === 2 && row?.total_points === P && row?.submissions === 1 && row?.kg_per_student === 2,
    'group row has the student, 2 kg, the verified points and 1 submission', row);
  check(live.json.data.by_category.some((c) => c.category === 'Plastic' && c.total_kg >= 2), 'by_category includes the plastic', live.json.data.by_category);
  check(live.json.data.totals.kg >= 2 && live.json.data.totals.submissions >= 1, 'totals include the verified waste', live.json.data.totals);
  check((await call('GET', '/admin/reports/impact?month=nope', { token: admin })).status === 422, 'invalid month is rejected (422)', null);
  check((await call('GET', '/admin/reports/impact', { token: staff })).status === 403, 'staff cannot see reports (403)', null);

  const gen = await call('POST', '/admin/reports/impact/generate', { token: admin, body: { month } });
  check(gen.status === 200 && gen.json.data.reports.some((r) => r.group?.id === gid && r.total_kg_diverted === 2), 'generate stores a snapshot row per group', gen);
  await call('POST', '/admin/reports/impact/generate', { token: admin, body: { month } });
  const hist = await call('GET', `/admin/reports/impact/history?group_id=${gid}`, { token: admin });
  check(hist.status === 200 && hist.json.total === 1, 'generating twice still leaves exactly one row (refreshed, not duplicated)', hist);
  check(hist.json.data[0]?.month === month && hist.json.data[0]?.total_points === P, 'history row has the right month and points', hist.json.data?.[0]);

  const csvG = await csv(`/admin/reports/impact/export?month=${month}&type=groups`, admin);
  check(csvG.status === 200 && csvG.type.includes('text/csv'), 'CSV export of groups downloads as text/csv', { status: csvG.status, type: csvG.type });
  check(csvG.text.includes('kg_diverted') && csvG.text.includes(gname), 'CSV has the header and the group row', csvG.text.slice(0, 300));
  const csvC = await csv(`/admin/reports/impact/export?month=${month}&type=categories`, admin);
  check(csvC.status === 200 && csvC.text.includes('Plastic'), 'CSV export of categories works', csvC.text.slice(0, 300));

  // ---- cleanup: empty the group, then delete it (stored reports go with it) -------------------
  await call('PATCH', `/admin/users/${studentId}`, { token: admin, body: { group_id: null } });
  check((await call('DELETE', `/admin/groups/${gid}`, { token: admin })).status === 204, 'an empty group can be deleted (204)', null);
  check((await call('GET', `/admin/reports/impact/history?group_id=${gid}`, { token: admin })).json.total === 0, "the group's stored reports were removed with it", null);

  console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error('Smoke test crashed:', e.message);
  console.error('Is the server running at', BASE, '? Did you run "php artisan migrate" and "php artisan db:seed"?');
  process.exit(2);
});
