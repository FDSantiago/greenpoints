import { useState } from 'react';
import { api } from '../../lib/api.js';
import { useApi } from '../../hooks/useApi.js';
import { Async, Bars, ErrorBox, PageHead, useAction } from '../../components/ui.jsx';
import { kg, pts, thisMonth } from '../../lib/format.js';

// There is no single "admin dashboard" endpoint, so we combine three small ones.
export default function Overview() {
  const [month, setMonth] = useState(thisMonth());
  const report = useApi('/admin/reports/impact', { month });
  const users = useApi('/admin/users', { per_page: 1 });          // we only want `total`
  const pending = useApi('/staff/submissions', { status: 'pending', per_page: 1 });
  const history = useApi('/admin/reports/impact/history', { per_page: 12 });
  const [run, busy, error] = useAction();
  const [msg, setMsg] = useState('');

  const generate = () => run(async () => {
    await api('/admin/reports/impact/generate', { method: 'POST', body: { month } });
    setMsg(`Snapshot saved for ${month}.`); history.reload();
  });
  // The export needs the Authorization header, so we fetch a blob and download it ourselves.
  const exportCsv = (type) => run(async () => {
    const blob = await api('/admin/reports/impact/export', { params: { month, type }, blob: true });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `impact-${type}-${month}.csv` });
    a.click(); URL.revokeObjectURL(a.href);
  });

  return (
    <>
      <PageHead title="Overview" sub="Verified waste only, grouped by each student's current group.">
        <input type="month" className="chip" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Month" />
      </PageHead>
      <ErrorBox error={error} />
      {msg && <div className="alert ok">{msg}</div>}
      <Async state={report}>
        {({ data: r }) => (
          <>
            <section className="hero">
              <div className="label">Waste diverted from landfill in {r.month}</div>
              <div className="big">{kg(r.totals.kg)}</div>
              <div className="label">{pts(r.totals.points)} points awarded · {r.totals.submissions} verified drop-offs</div>
              <div className="row-actions">
                <button className="btn light sm" disabled={busy} onClick={generate}>Save monthly snapshot</button>
                <button className="btn sm" style={{ background: 'rgba(255,255,255,.18)' }} disabled={busy} onClick={() => exportCsv('groups')}>Export groups CSV</button>
                <button className="btn sm" style={{ background: 'rgba(255,255,255,.18)' }} disabled={busy} onClick={() => exportCsv('categories')}>Export categories CSV</button>
              </div>
            </section>
            <div className="stats">
              <div className="card stat"><div className="num">{users.data?.total ?? '…'}</div><div className="cap">Registered users</div></div>
              <div className="card stat"><div className="num">{pending.data?.total ?? '…'}</div><div className="cap">Pending drop-offs</div></div>
              <div className="card stat"><div className="num">{r.groups.length}</div><div className="cap">Groups with activity</div></div>
            </div>
            <div className="cols even">
              <div className="card">
                <h2>Groups</h2>
                <div className="tablewrap"><table>
                  <thead><tr><th>Group</th><th>Students</th><th>kg</th><th>kg / student</th><th>Points</th></tr></thead>
                  <tbody>{r.groups.map((g) => <tr key={g.group_id}><td><b>{g.name}</b></td><td>{g.students}</td><td>{g.total_kg}</td><td>{g.kg_per_student}</td><td>{g.total_points}</td></tr>)}</tbody>
                </table></div>
              </div>
              <div className="card">
                <h2>Waste by category</h2>
                <Bars items={r.by_category.map((c) => ({ label: c.category, value: c.total_kg }))} />
              </div>
            </div>
          </>
        )}
      </Async>
      <div className="card" style={{ marginTop: 16 }}>
        <h2>Saved snapshots</h2>
        <Async state={history}>
          {(p) => (
            <div className="tablewrap"><table>
              <thead><tr><th>Month</th><th>Group</th><th>kg</th><th>Points</th></tr></thead>
              <tbody>{p.data.map((h) => <tr key={h.id}><td>{h.month}</td><td>{h.group?.name}</td><td>{h.total_kg_diverted}</td><td>{h.total_points}</td></tr>)}</tbody>
            </table></div>
          )}
        </Async>
      </div>
    </>
  );
}
