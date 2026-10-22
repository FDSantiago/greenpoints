import { useState } from 'react';
import { useApi } from '../hooks/useApi.js';
import { Async, Bars, PageHead } from '../components/ui.jsx';
import { kg, pts, thisMonth } from '../lib/format.js';

// "My impact" = the monthly summary endpoint (only verified waste counts).
export default function Impact() {
  const [month, setMonth] = useState(thisMonth());
  const state = useApi('/waste-submissions/summary', { month });
  return (
    <>
      <PageHead title="My impact" sub="Waste you kept out of the landfill.">
        <input type="month" value={month} max={thisMonth()} onChange={(e) => e.target.value && setMonth(e.target.value)} className="chip" aria-label="Month" />
      </PageHead>
      <Async state={state}>
        {({ data: s }) => (
          <>
            <section className="hero">
              <div className="label">Recycled in {s.month}</div>
              <div className="big">{kg(s.total_kg)}</div>
              <div className="label">{pts(s.total_points)} points · {s.verified_submissions} verified drop-offs</div>
            </section>
            <div className="card" style={{ marginTop: 16 }}>
              <h2>Waste by category</h2>
              <Bars items={s.by_category.map((c) => ({ label: c.category, value: c.total_kg }))} />
              {s.pending_submissions > 0 && <p className="muted small" style={{ marginTop: 10 }}>{s.pending_submissions} drop-off(s) still waiting for staff are not counted yet.</p>}
            </div>
          </>
        )}
      </Async>
    </>
  );
}
