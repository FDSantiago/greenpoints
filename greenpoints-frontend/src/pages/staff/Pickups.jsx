import { useState } from 'react';
import { api } from '../../lib/api.js';
import { useApi } from '../../hooks/useApi.js';
import { Async, Chips, Empty, ErrorBox, PageHead, Pager, Pill, useAction } from '../../components/ui.jsx';
import { dateTime } from '../../lib/format.js';

export default function Pickups() {
  const [status, setStatus] = useState('pending');
  const [code, setCode] = useState('');
  const [page, setPage] = useState(1);
  // Searching by code looks through all statuses (server behaviour), so we drop the status filter then.
  const state = useApi('/staff/redemptions', { status: code ? undefined : status, code: code.trim(), page });
  const [run, busy, error] = useAction();

  const act = (r, action) => run(async () => { await api(`/staff/redemptions/${r.id}/${action}`, { method: 'POST' }); state.reload(); });

  return (
    <>
      <PageHead title="Reward pickups" sub="Mark rewards ready, then claimed when handed over." />
      <div className="field" style={{ maxWidth: 320 }}><input placeholder="Search by code, e.g. K7M2QX9A" value={code} onChange={(e) => { setCode(e.target.value); setPage(1); }} aria-label="Search by code" /></div>
      {!code && <Chips value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[['pending', 'Pending'], ['ready', 'Ready'], ['claimed', 'Claimed'], ['cancelled', 'Cancelled']]} />}
      <ErrorBox error={error} />
      <Async state={state}>
        {(p) => (
          <div className="card">
            {p.data.length === 0 && <Empty>No redemptions found.</Empty>}
            {p.data.map((r) => (
              <div className="row" key={r.id} style={{ flexWrap: 'wrap' }}>
                <span className="ic">🎁</span>
                <div className="grow"><b>{r.reward.name} · {r.code}</b><span className="muted small">{r.user?.name} ({r.user?.group || 'no group'}) · {dateTime(r.redeemed_at)}</span></div>
                <Pill status={r.status} />
                {r.status === 'pending' && <button className="btn ghost sm" disabled={busy} onClick={() => act(r, 'ready')}>Mark ready</button>}
                {['pending', 'ready'].includes(r.status) && <button className="btn sm" disabled={busy} onClick={() => act(r, 'claim')}>Mark claimed</button>}
              </div>
            ))}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}
