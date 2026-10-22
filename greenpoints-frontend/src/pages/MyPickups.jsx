import { useState } from 'react';
import { api } from '../lib/api.js';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, ErrorBox, PageHead, Pager, Pill, useAction } from '../components/ui.jsx';
import { date, pts } from '../lib/format.js';

export default function MyPickups() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const state = useApi('/redemptions', { status, page });
  const [run, busy, error] = useAction();

  const cancel = (r) => {
    if (!confirm(`Cancel ${r.reward.name}? Your ${pts(r.points_spent)} points will be refunded.`)) return;
    run(async () => { await api(`/redemptions/${r.id}/cancel`, { method: 'POST' }); state.reload(); });
  };

  return (
    <>
      <PageHead title="My reward pickups" sub="Show your code at the pickup desk." />
      <Chips value={status} onChange={(v) => { setStatus(v); setPage(1); }}
        options={[['', 'All'], ['pending', 'Pending'], ['ready', 'Ready'], ['claimed', 'Claimed'], ['cancelled', 'Cancelled']]} />
      <ErrorBox error={error} />
      <Async state={state}>
        {(p) => (
          <div className="card">
            {p.data.length ? p.data.map((r) => (
              <div className="row" key={r.id}>
                <span className="ic">🎁</span>
                <div className="grow"><b>{r.reward.name}</b><span className="muted small">Code {r.code} · {date(r.redeemed_at)} · {pts(r.points_spent)} pts</span></div>
                <Pill status={r.status} />
                {r.status === 'pending' && <button className="btn ghost sm" disabled={busy} onClick={() => cancel(r)}>Cancel</button>}
              </div>
            )) : <Empty>No redemptions yet.</Empty>}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}
