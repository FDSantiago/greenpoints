// End-to-end smoke test: wallet, rewards, redemption, staff fulfilment, leaderboard, dashboard.
// Run from the Laravel project root while `php artisan serve --host=0.0.0.0` is running:
//     node scripts/smoke-wallet-leaderboard.mjs
// Tip: for a REAL concurrency test of the double-spend protection, start the server with workers:
//     PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0
// It registers brand-new students each run (so balances start at 0) and uses staff@greenpoints.test.
// Needs the seeded data (php artisan db:seed) and GREENPOINTS_DAILY_POINTS_CAP unset.
// Login is throttled to 5/min per IP: if you just ran the other smoke script, wait a minute.

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
  try { json = await res.json(); } catch { /* non-JSON body */ }
  return { status: res.status, json, headers: res.headers };
}

async function registerStudent(label) {
  const email = `smoke+${label}${Date.now()}@greenpoints.test`;
  const r = await call('POST', '/auth/register', {
    body: { name: `Smoke ${label}`, email, password: 'password123', password_confirmation: 'password123' },
  });
  if (r.status !== 201) {
    console.log('Cannot register a test student:', r.status, JSON.stringify(r.json).slice(0, 500));
    process.exit(2);
  }
  return r.json.token;
}

async function loginStaff() {
  const r = await call('POST', '/auth/login', { body: { email: 'staff@greenpoints.test', password: 'password' } });
  if (r.status === 429) {
    console.log('Login is throttled (5/min). Wait 60 seconds and run again.');
    process.exit(2);
  }
  if (r.status !== 200) {
    console.log(`Cannot log in as staff (HTTP ${r.status}). Did you run "php artisan db:seed"?`, r.json);
    process.exit(2);
  }
  return r.json.token;
}

const wallet = async (token) => (await call('GET', '/wallet', { token })).json.data;
const stockOf = async (token, id) => (await call('GET', `/rewards/${id}`, { token })).json.data.stock_qty;

async function main() {
  const staff = await loginStaff();
  const student = await registerStudent('a');
  const other = await registerStudent('b');

  const catRes = await call('GET', '/waste-categories', { token: student });
  const plastic = catRes.json.data.find((c) => c.name === 'Plastic');
  const factor = catRes.json.condition_multipliers.clean ?? 1;

  // Earn `points` by submitting plastic and having staff verify it. Returns points actually awarded.
  async function earn(token, points) {
    let kg = Math.ceil(points / (plastic.points_per_kg * factor));
    const items = [];
    while (kg > 0) {
      const w = Math.min(100, kg);
      items.push({ category_id: plastic.id, weight_kg: w, waste_condition: 'clean' });
      kg -= w;
    }
    const sub = await call('POST', '/waste-submissions', { token, body: { items } });
    const ver = await call('POST', `/staff/submissions/${sub.json.data.id}/verify`, { token: staff });
    if (ver.status !== 200) throw new Error(`could not earn points: ${JSON.stringify(ver.json)}`);
    return ver.json.data.points_awarded;
  }

  // ---- fresh wallet --------------------------------------------------------------------------
  const w0 = await call('GET', '/wallet', { token: student });
  check(w0.status === 200 && w0.json.data.balance === 0, 'new student starts with balance 0', w0);
  check(w0.json.data.next_reward !== null, 'wallet shows a next_reward goal', w0.json);

  const rewards = await call('GET', '/rewards', { token: student });
  check(rewards.status === 200 && rewards.json.data.length >= 1, 'GET /rewards lists rewards', rewards);
  const reward = rewards.json.data.find((r) => r.in_stock); // list is sorted cheapest first
  const cost = reward.points_cost;
  check(rewards.json.data.every((r) => r.can_afford === false), 'nothing is affordable at 0 points', rewards.json);
  const aff0 = await call('GET', '/rewards?affordable=true', { token: student });
  check(aff0.status === 200 && aff0.json.data.length === 0, 'affordable=true is empty at 0 points', aff0);

  const broke = await call('POST', `/rewards/${reward.id}/redeem`, { token: student });
  check(broke.status === 422, 'redeeming without enough points is refused (422)', broke);
  const noSuch = await call('POST', '/rewards/999999/redeem', { token: student });
  check(noSuch.status === 404, 'redeeming an unknown reward is 404', noSuch);

  // ---- earn exactly enough for one reward ----------------------------------------------------
  const B = await earn(student, cost);
  let totalEarned = B;
  check(B >= cost && B < cost * 2, `earned ${B} points (cost ${cost}); enough for exactly one redemption`, { B, cost });

  const w1 = (await call('GET', '/wallet', { token: student })).json.data;
  check(w1.balance === B, 'wallet balance equals points earned', w1);
  check(w1.earned_this_month === B, 'earned_this_month equals points earned', w1);

  const earned = await call('GET', '/wallet/transactions?type=earned', { token: student });
  check(earned.status === 200 && earned.json.data.length >= 1 && earned.json.data.every((t) => t.points > 0),
    'earned tab lists only positive transactions', earned);
  check(earned.json.data[0]?.waste?.category === 'Plastic', 'earned transaction shows the waste category', earned.json);
  const redeemed0 = await call('GET', '/wallet/transactions?type=redeemed', { token: student });
  check(redeemed0.status === 200 && redeemed0.json.data.length === 0, 'redeemed tab is empty before redeeming', redeemed0);

  const aff1 = await call('GET', '/rewards?affordable=true', { token: student });
  check(aff1.json.data.some((r) => r.id === reward.id), 'reward is now affordable', aff1.json);
  const detail = await call('GET', `/rewards/${reward.id}`, { token: student });
  check(detail.json.data.preview.balance_after === B - cost, 'reward preview shows balance_after', detail.json);
  const stockBefore = detail.json.data.stock_qty;

  // ---- the double-spend race: two simultaneous redemptions, only one may win ------------------
  const k1 = `smoke-${Date.now()}-1`;
  const k2 = `smoke-${Date.now()}-2`;
  const [r1, r2] = await Promise.all([
    call('POST', `/rewards/${reward.id}/redeem`, { token: student, headers: { 'Idempotency-Key': k1 } }),
    call('POST', `/rewards/${reward.id}/redeem`, { token: student, headers: { 'Idempotency-Key': k2 } }),
  ]);
  const statuses = [r1.status, r2.status].sort();
  check(statuses[0] === 201 && statuses[1] === 422, `two simultaneous redeems: exactly one wins (got ${statuses})`, [r1.json, r2.json]);
  const winner = r1.status === 201 ? r1 : r2;
  const winnerKey = r1.status === 201 ? k1 : k2;
  const redemption = winner.json.data;
  check(/^[A-Z0-9]{8}$/.test(redemption.code), `redemption code looks right (${redemption.code})`, redemption);
  check(redemption.status === 'pending' && redemption.points_spent === cost, 'redemption is pending and charged the reward cost', redemption);
  check(winner.json.balance_after === B - cost, 'balance_after in the response is correct', winner.json);

  const replay = await call('POST', `/rewards/${reward.id}/redeem`, { token: student, headers: { 'Idempotency-Key': winnerKey } });
  check(replay.status === 200 && replay.json.data.id === redemption.id, 'replaying the same Idempotency-Key returns the same redemption (200)', replay);
  check(replay.headers.get('Idempotent-Replayed') === 'true', 'replay is flagged with an Idempotent-Replayed header', null);
  const badKey = await call('POST', `/rewards/${reward.id}/redeem`, { token: student, headers: { 'Idempotency-Key': 'bad key!' } });
  check(badKey.status === 422, 'malformed Idempotency-Key is rejected (422)', badKey);

  check((await wallet(student)).balance === B - cost, 'balance dropped by exactly one reward cost', null);
  check((await stockOf(student, reward.id)) === stockBefore - 1, 'stock dropped by exactly one', null);
  const redeemed1 = await call('GET', '/wallet/transactions?type=redeemed', { token: student });
  check(redeemed1.json.data.length === 1 && redeemed1.json.data[0].points === -cost, 'redeemed tab shows the -cost transaction', redeemed1);
  check(redeemed1.json.data[0]?.redemption?.reward === reward.name, 'redeemed transaction names the reward', redeemed1.json);

  // ---- my redemptions ------------------------------------------------------------------------
  const mine = await call('GET', '/redemptions', { token: student });
  check(mine.status === 200 && mine.json.data.some((r) => r.id === redemption.id), 'redemption appears in my list', mine);
  check((await call('GET', `/redemptions/${redemption.id}`, { token: student })).status === 200, 'owner can open the redemption', null);
  check((await call('GET', `/redemptions/${redemption.id}`, { token: other })).status === 404, "another student can't see it (404)", null);

  // ---- staff fulfilment ----------------------------------------------------------------------
  check((await call('GET', '/staff/redemptions', { token: student })).status === 403, 'student is blocked from staff redemptions (403)', null);
  const found = await call('GET', `/staff/redemptions?code=${redemption.code.toLowerCase()}`, { token: staff });
  check(found.status === 200 && found.json.data.length === 1 && found.json.data[0].user?.name, 'staff can look a redemption up by code', found);
  const queue = await call('GET', '/staff/redemptions', { token: staff });
  check(queue.status === 200 && queue.json.data.some((r) => r.id === redemption.id), 'it is in the pending queue', queue);

  const ready = await call('POST', `/staff/redemptions/${redemption.id}/ready`, { token: staff });
  check(ready.status === 200 && ready.json.data.status === 'ready', 'staff marks it ready', ready);
  check((await call('POST', `/staff/redemptions/${redemption.id}/ready`, { token: staff })).status === 409, 'marking ready twice is blocked (409)', null);
  const claimed = await call('POST', `/staff/redemptions/${redemption.id}/claim`, { token: staff });
  check(claimed.status === 200 && claimed.json.data.status === 'claimed' && claimed.json.data.fulfilled_at, 'staff marks it claimed', claimed);
  check((await call('POST', `/staff/redemptions/${redemption.id}/claim`, { token: staff })).status === 409, 'claiming twice is blocked (409)', null);
  check((await call('POST', `/redemptions/${redemption.id}/cancel`, { token: student })).status === 409, "a claimed redemption can't be cancelled (409)", null);

  // ---- cancel + refund -----------------------------------------------------------------------
  totalEarned += await earn(student, cost);
  const balBefore = (await wallet(student)).balance;
  const stock2 = await stockOf(student, reward.id);
  const again = await call('POST', `/rewards/${reward.id}/redeem`, { token: student, headers: { 'Idempotency-Key': `smoke-${Date.now()}-3` } });
  check(again.status === 201, 'second redemption succeeds after earning more', again);
  const cancelled = await call('POST', `/redemptions/${again.json.data.id}/cancel`, { token: student });
  check(cancelled.status === 200 && cancelled.json.data.status === 'cancelled', 'student cancels a pending redemption', cancelled);
  check((await wallet(student)).balance === balBefore, 'cancel refunded the points', { balBefore });
  check((await stockOf(student, reward.id)) === stock2, 'cancel returned the stock', null);
  check((await call('POST', `/redemptions/${again.json.data.id}/cancel`, { token: student })).status === 409, 'cancelling twice is blocked (409)', null);
  check((await call('POST', `/redemptions/${again.json.data.id}/cancel`, { token: other })).status === 404, "another student can't cancel it (404)", null);

  // ---- leaderboard ---------------------------------------------------------------------------
  const ind = await call('GET', '/leaderboard?scope=individual&period=weekly&limit=50', { token: student });
  check(ind.status === 200 && Array.isArray(ind.json.data.entries), 'individual leaderboard works', ind);
  check(ind.json.data.me?.points === totalEarned, `my leaderboard points are earned points only (${totalEarned}); spending never lowers rank`, ind.json.data.me);
  check(ind.json.data.me?.rank >= 1, 'my rank is present', ind.json.data.me);
  const grp = await call('GET', '/leaderboard?scope=group&period=monthly&metric=per_member', { token: student });
  check(grp.status === 200 && (grp.json.data.entries.length === 0 || 'per_member' in grp.json.data.entries[0]), 'group leaderboard supports metric=per_member', grp);
  check((await call('GET', '/leaderboard?scope=nope', { token: student })).status === 422, 'invalid scope is rejected (422)', null);

  // ---- dashboard -----------------------------------------------------------------------------
  const dash = await call('GET', '/dashboard', { token: student });
  check(dash.status === 200, 'GET /dashboard works', dash);
  check(dash.json.data.points.balance === (await wallet(student)).balance, 'dashboard balance matches the wallet', dash.json.data.points);
  check(Array.isArray(dash.json.data.recent_submissions) && dash.json.data.recent_submissions.length >= 1, 'dashboard lists recent submissions', dash.json.data);
  check(dash.json.data.rank.individual?.rank >= 1, 'dashboard includes my rank', dash.json.data.rank);

  console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error('Smoke test crashed:', e.message);
  console.error('Is the server running at', BASE, '? Did you run the new migration and db:seed?');
  process.exit(2);
});
