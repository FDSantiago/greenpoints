// End-to-end smoke test for the GreenPoints core flow.
// Run from the Laravel project root while `php artisan serve --host=0.0.0.0` is running:
//     node scripts/smoke-core-flow.mjs
// Needs the seeded users (php artisan db:seed). It writes real rows to your dev DB.
// Reset everything afterwards with:  php artisan migrate:fresh --seed

const BASE = process.env.API_URL ?? 'http://localhost:8000/api/v1';
let failures = 0;

function check(cond, label, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);
  if (!cond) {
    failures++;
    if (detail !== undefined) console.log('      ->', JSON.stringify(detail).slice(0, 600));
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
  try { json = await res.json(); } catch { /* non-JSON body */ }
  return { status: res.status, json };
}

async function login(email) {
  const r = await call('POST', '/auth/login', { body: { email, password: 'password' } });
  if (r.status !== 200) {
    console.log(`Cannot log in as ${email} (HTTP ${r.status}). Did you run "php artisan db:seed"?`, r.json);
    process.exit(2);
  }
  return r.json.token;
}

const balance = async (token) => (await call('GET', '/me', { token })).json.data.points_balance;

async function main() {
  const student = await login('student1@greenpoints.test');
  const student2 = await login('student2@greenpoints.test');
  const staff = await login('staff@greenpoints.test');

  // 1. categories
  const cats = await call('GET', '/waste-categories', { token: student });
  check(cats.status === 200 && cats.json.data.length >= 2, 'GET /waste-categories returns categories', cats);
  const plastic = cats.json.data.find((c) => c.name === 'Plastic');
  const paper = cats.json.data.find((c) => c.name === 'Paper');

  // 2. validation
  const bad = await call('POST', '/waste-submissions', {
    token: student,
    body: { items: [{ category_id: plastic.id, weight_kg: 0 }] },
  });
  check(bad.status === 422, 'zero weight is rejected with 422', bad);
  const bad2 = await call('POST', '/waste-submissions', {
    token: student,
    body: { items: [{ category_id: 99999, weight_kg: 1 }] },
  });
  check(bad2.status === 422, 'unknown category is rejected with 422', bad2);

  // 3. create a submission
  const before = await balance(student);
  const created = await call('POST', '/waste-submissions', {
    token: student,
    body: {
      notes: 'smoke test',
      items: [
        { category_id: plastic.id, weight_kg: 1.2, waste_condition: 'clean' },
        { category_id: paper.id, weight_kg: 0.5, waste_condition: 'contaminated' },
      ],
    },
  });
  check(created.status === 201, 'POST /waste-submissions -> 201', created);
  check(created.json?.data?.status === 'pending', 'new submission is pending', created.json);
  check(created.json?.data?.items?.length === 2, 'submission has 2 items', created.json);
  check(created.json?.data?.points_awarded === null, 'no points before verification', created.json);
  const id = created.json.data.id;

  // 4. student history + detail
  const mine = await call('GET', '/waste-submissions?status=pending', { token: student });
  check(mine.status === 200 && mine.json.data.some((s) => s.id === id), 'submission appears in student history', mine);
  const detail = await call('GET', `/waste-submissions/${id}`, { token: student });
  check(detail.status === 200 && detail.json.data.id === id, 'GET /waste-submissions/{id} works for the owner', detail);
  const other = await call('GET', `/waste-submissions/${id}`, { token: student2 });
  check(other.status === 404, "another student can't see it (404)", other);

  // 5. permissions
  const nope = await call('GET', '/staff/submissions', { token: student });
  check(nope.status === 403, 'student is blocked from /staff/submissions (403)', nope);
  const nope2 = await call('POST', `/staff/submissions/${id}/verify`, { token: student });
  check(nope2.status === 403, 'student cannot verify (403)', nope2);

  // 6. staff queue + verify
  const queue = await call('GET', '/staff/submissions?status=pending', { token: staff });
  check(queue.status === 200 && queue.json.data.some((s) => s.id === id), 'staff sees it in the pending queue', queue);
  check(queue.json.data.find((s) => s.id === id)?.user?.name !== undefined, 'staff queue includes the submitter', queue.json);

  const verified = await call('POST', `/staff/submissions/${id}/verify`, { token: staff });
  check(verified.status === 200, 'staff verify -> 200', verified);
  check(verified.json?.data?.status === 'verified', 'status is verified', verified.json);
  const pts = verified.json?.data?.points_awarded;
  check(Number.isInteger(pts) && pts > 0, `points awarded (${pts})`, verified.json);

  const again = await call('POST', `/staff/submissions/${id}/verify`, { token: staff });
  check(again.status === 409, 'verifying twice is blocked (409)', again);

  // 7. ledger / balance
  const after = await balance(student);
  check(after - before === pts, `balance increased by exactly ${pts} (was ${before}, now ${after})`, { before, after, pts });

  // 8. monthly summary
  const summary = await call('GET', '/waste-submissions/summary', { token: student });
  check(summary.status === 200 && summary.json.data.total_points >= pts, 'monthly summary includes the verified points', summary);

  // 9. reject path: no points
  const second = await call('POST', '/waste-submissions', {
    token: student,
    body: { items: [{ category_id: paper.id, weight_kg: 0.3 }] },
  });
  const rej = await call('POST', `/staff/submissions/${second.json.data.id}/reject`, {
    token: staff,
    body: { reason: 'Not sorted properly' },
  });
  check(rej.status === 200 && rej.json.data.status === 'rejected', 'staff can reject with a reason', rej);
  const noReason = await call('POST', `/staff/submissions/${second.json.data.id}/reject`, { token: staff, body: {} });
  check([409, 422].includes(noReason.status), 'reject without reason is refused', noReason);
  check((await balance(student)) === after, 'rejection awards no points', null);

  // 10. staff cannot verify their own submission
  const own = await call('POST', '/waste-submissions', {
    token: staff,
    body: { items: [{ category_id: plastic.id, weight_kg: 2 }] },
  });
  const selfVerify = await call('POST', `/staff/submissions/${own.json.data.id}/verify`, { token: staff });
  check(selfVerify.status === 403, 'staff cannot verify their own submission (403)', selfVerify);

  console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error('Smoke test crashed:', e.message);
  console.error('Is the server running at', BASE, '?');
  process.exit(2);
});
