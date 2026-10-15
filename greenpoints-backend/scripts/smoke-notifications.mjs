// End-to-end smoke test for in-app notifications.
// Run from the Laravel project root while `php artisan serve --host=0.0.0.0` is running:
//     node scripts/smoke-notifications.mjs
// Needs the seeded accounts (php artisan db:seed) and the newest migration (php artisan migrate).
// Uses 2 logins (staff + admin); login is throttled to 5/min per IP, so wait a minute after another smoke script.
// GREENPOINTS_DAILY_POINTS_CAP must be unset. It registers fresh students, so balances start at 0.

const BASE = process.env.API_URL ?? 'http://localhost:8000/api/v1';
let failures = 0;

function check(cond, label, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);
  if (!cond) {
    failures++;
    if (detail !== undefined) console.log('      ->', JSON.stringify(detail).slice(0, 700));
  }
}

async function call(method, path, { token, body } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  try { json = await res.json(); } catch { /* 204 */ }
  return { status: res.status, json };
}

async function loginOrExit(email) {
  const r = await call('POST', '/auth/login', { body: { email, password: 'password' } });
  if (r.status === 429) { console.log('Login is throttled (5/min). Wait 60 seconds and run again.'); process.exit(2); }
  if (r.status !== 200) { console.log(`Cannot log in as ${email} (HTTP ${r.status}). Did you run db:seed and migrate?`, r.json); process.exit(2); }
  return r.json.token;
}

async function register(label) {
  const r = await call('POST', '/auth/register', {
    body: {
      name: `Smoke ${label}`, email: `smoke+n${label}${Date.now()}@greenpoints.test`,
      password: 'password123', password_confirmation: 'password123',
    },
  });
  if (r.status !== 201) { console.log('Cannot register a test student:', r.status, JSON.stringify(r.json).slice(0, 400)); process.exit(2); }
  return { token: r.json.token, id: r.json.user.id };
}

const list = async (token, query = '') => (await call('GET', `/notifications?per_page=50${query}`, { token })).json;
const unread = async (token) => (await call('GET', '/notifications/unread-count', { token })).json?.data?.unread;
const dashboardUnread = async (token) => (await call('GET', '/dashboard', { token })).json?.data?.unread_notifications;

async function main() {
  const staff = await loginOrExit('staff@greenpoints.test');
  const admin = await loginOrExit('admin@greenpoints.test');
  const { token: student, id: studentId } = await register('a');
  const { token: other } = await register('b');

  const cats = await call('GET', '/waste-categories', { token: student });
  const plastic = cats.json.data.find((c) => c.name === 'Plastic');
  const paper = cats.json.data.find((c) => c.name === 'Paper');
  const factor = cats.json.condition_multipliers.clean ?? 1;

  async function submit(token, items) {
    return call('POST', '/waste-submissions', { token, body: { items } });
  }
  async function earn(token, points) {
    let kg = Math.ceil(points / (plastic.points_per_kg * factor));
    const items = [];
    while (kg > 0) { const w = Math.min(100, kg); items.push({ category_id: plastic.id, weight_kg: w, waste_condition: 'clean' }); kg -= w; }
    const sub = await submit(token, items);
    const ver = await call('POST', `/staff/submissions/${sub.json.data.id}/verify`, { token: staff });
    return ver.json.data.points_awarded;
  }

  // ---- empty to start --------------------------------------------------------------------------
  check((await call('GET', '/notifications')).status === 401, 'notifications need a token (401)', null);
  const n0 = await list(student);
  check(n0.total === 0 && n0.data.length === 0, 'a new student has no notifications', n0);
  check((await unread(student)) === 0, 'unread-count starts at 0', null);
  check((await dashboardUnread(student)) === 0, 'dashboard.unread_notifications starts at 0', null);

  // ---- verified drop-off -> "points added" (goal) ----------------------------------------------
  const subA = await submit(student, [{ category_id: plastic.id, weight_kg: 2, waste_condition: 'clean' }]);
  const idA = subA.json.data.id;
  check((await list(student)).total === 0, 'submitting alone does not notify (nothing happened yet)', null);
  const verA = await call('POST', `/staff/submissions/${idA}/verify`, { token: staff });
  const P = verA.json.data.points_awarded;
  const nA = (await list(student)).data;
  check(nA.length === 1 && nA[0].style === 'goal', 'verifying creates a gold "goal" notification', nA);
  check(nA[0]?.title.includes(`${P} points`) && nA[0]?.body.includes('2 kg'), `it says ${P} points and the weight`, nA[0]);
  check(nA[0]?.link?.type === 'submission' && nA[0]?.link?.id === idA, 'it links to the submission', nA[0]?.link);
  check(nA[0]?.read === false && nA[0]?.read_at === null, 'it starts unread', nA[0]);
  check((await unread(student)) === 1 && (await dashboardUnread(student)) === 1, 'unread-count and dashboard both say 1', null);
  check((await call('POST', `/staff/submissions/${idA}/verify`, { token: staff })).status === 409, 'verifying twice is still blocked (409)', null);
  check((await list(student)).total === 1, 'a blocked second verify sends no duplicate notification', null);

  // ---- reading ---------------------------------------------------------------------------------
  const nid = nA[0].id;
  const rd = await call('POST', `/notifications/${nid}/read`, { token: student });
  check(rd.status === 200 && rd.json.data.read === true && rd.json.data.read_at, 'mark one as read', rd);
  check((await call('POST', `/notifications/${nid}/read`, { token: student })).status === 200, 'marking read twice is fine (idempotent)', null);
  check((await unread(student)) === 0, 'unread-count drops to 0', null);
  check((await call('POST', `/notifications/${nid}/read`, { token: other })).status === 404, "another student can't touch it (404)", null);
  check((await call('POST', '/notifications/00000000-0000-4000-8000-000000000000/read', { token: student })).status === 404, 'an unknown id is 404', null);

  // ---- rejected drop-off -> alert --------------------------------------------------------------
  const subB = await submit(student, [{ category_id: paper.id, weight_kg: 0.3 }]);
  const rej = await call('POST', `/staff/submissions/${subB.json.data.id}/reject`, { token: staff, body: { reason: 'Not sorted properly' } });
  check(rej.status === 200, 'staff rejects the second drop-off', rej);
  const nB = (await list(student)).data;
  check(nB.length === 2 && nB[0].style === 'alert' && nB[0].title === 'Drop-off rejected', 'rejecting creates a red "alert" notification (newest first)', nB[0]);
  check(nB[0]?.body.includes('Not sorted properly') && nB[0]?.link?.id === subB.json.data.id, 'it carries the reason and links to the submission', nB[0]);

  // ---- redemption lifecycle --------------------------------------------------------------------
  const rewards = await call('GET', '/rewards', { token: student });
  const reward = rewards.json.data.find((r) => r.in_stock);
  const cost = reward.points_cost;
  await earn(student, cost * 2);
  check((await list(student)).total === 3, 'earning more points adds a third notification', null);

  const red1 = await call('POST', `/rewards/${reward.id}/redeem`, { token: student });
  check(red1.status === 201, 'student redeems the reward', red1);
  check((await list(student)).total === 3, 'redeeming itself does not notify (the student just did it)', null);
  const code = red1.json.data.code;
  const rid = red1.json.data.id;

  await call('POST', `/staff/redemptions/${rid}/ready`, { token: staff });
  let nR = (await list(student)).data;
  check(nR.length === 4 && nR[0].style === 'info' && nR[0].title === 'Your reward is ready for pickup', 'marking ready notifies the student', nR[0]);
  check(nR[0]?.body.includes(code) && nR[0]?.body.includes(reward.name), 'it includes the reward name and the pickup code', nR[0]);
  check(nR[0]?.link?.type === 'redemption' && nR[0]?.link?.id === rid, 'it links to the redemption', nR[0]?.link);

  await call('POST', `/staff/redemptions/${rid}/claim`, { token: staff });
  nR = (await list(student)).data;
  check(nR.length === 5 && nR[0].title === 'Reward collected', 'claiming notifies the student', nR[0]);

  const red2 = await call('POST', `/rewards/${reward.id}/redeem`, { token: student });
  const staffCancel = await call('POST', `/redemptions/${red2.json.data.id}/cancel`, { token: staff });
  check(staffCancel.status === 200, 'staff cancel a pending redemption', staffCancel);
  nR = (await list(student)).data;
  check(nR.length === 6 && nR[0].style === 'alert' && nR[0].title === 'Redemption cancelled' && nR[0].body.includes('refunded'),
    'a staff cancellation notifies the student', nR[0]);

  const red3 = await call('POST', `/rewards/${reward.id}/redeem`, { token: student });
  const selfCancel = await call('POST', `/redemptions/${red3.json.data.id}/cancel`, { token: student });
  check(selfCancel.status === 200, 'student cancels their own redemption', selfCancel);
  check((await list(student)).total === 6, 'cancelling your own redemption sends no notification', null);

  // ---- admin point adjustments -----------------------------------------------------------------
  const plus = await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: 50, note: 'Event bonus.' } });
  const minus = await call('POST', `/admin/users/${studentId}/points-adjustments`, { token: admin, body: { points: -10, note: 'Correction.' } });
  check(plus.status === 201 && minus.status === 201, 'admin adjusts the points up and down', [plus.json, minus.json]);
  const nAdj = (await list(student)).data;
  check(nAdj.length === 8, 'two adjustments add two notifications (8 in total)', nAdj.length);
  check(nAdj[0].title === 'Points adjusted: -10' && nAdj[0].style === 'alert' && nAdj[1].title === 'Points adjusted: +50' && nAdj[1].style === 'goal',
    'adjustments: red for a deduction, gold for a bonus, newest first', [nAdj[0], nAdj[1]]);
  check(nAdj[1]?.body.includes('Event bonus.') && nAdj[1]?.body.includes('balance is now') && nAdj[1]?.link?.type === 'wallet', 'adjustment shows the admin note and new balance, linking to the wallet', nAdj[1]);

  // ---- lists, bulk actions ---------------------------------------------------------------------
  const unreadList = await list(student, '&status=unread');
  check(unreadList.total === 7 && unreadList.data.every((n) => n.read === false), 'status=unread lists the 7 unread ones (1 was marked read)', unreadList.total);
  const page = await call('GET', '/notifications?per_page=3', { token: student });
  check(page.json.data.length === 3 && page.json.last_page === 3 && page.json.total === 8, 'pagination works (per_page=3 -> 3 pages)', { last: page.json.last_page, total: page.json.total });
  const ordered = (await list(student)).data;
  check(ordered.every((n, i) => i === 0 || ordered[i - 1].created_at >= n.created_at), 'notifications are ordered newest first', ordered.map((n) => n.created_at));

  const all = await call('POST', '/notifications/read-all', { token: student });
  check(all.status === 200 && all.json.data.marked === 7, 'read-all marks the 7 unread ones', all);
  check((await unread(student)) === 0 && (await dashboardUnread(student)) === 0, 'unread-count and dashboard are back to 0', null);
  check((await list(student, '&status=unread')).total === 0, 'status=unread is now empty', null);
  check((await call('POST', '/notifications/read-all', { token: student })).json.data.marked === 0, 'read-all again marks nothing', null);

  const victim = ordered[0].id;
  check((await call('DELETE', `/notifications/${victim}`, { token: other })).status === 404, "another student can't delete it (404)", null);
  check((await call('DELETE', `/notifications/${victim}`, { token: student })).status === 204, 'the owner can dismiss one (204)', null);
  check((await list(student)).total === 7, 'the list now has 7', null);
  check((await list(other)).total === 0, "the other student's inbox stayed empty throughout", null);

  console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error('Smoke test crashed:', e.message);
  console.error('Is the server running at', BASE, '? Did you run "php artisan migrate" and "php artisan db:seed"?');
  process.exit(2);
});
