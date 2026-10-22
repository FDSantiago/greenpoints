import { useState } from 'react';
import { api } from '../../lib/api.js';
import { useApi } from '../../hooks/useApi.js';
import { Async, Chips, Empty, ErrorBox, PageHead, Pager, Pill, useAction } from '../../components/ui.jsx';
import { dateTime, iconFor } from '../../lib/format.js';

export default function Review() {
  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const state = useApi('/staff/submissions', { status, page, per_page: 10 });
  return (
    <>
      <PageHead title="Review drop-offs" sub="Weigh the waste, correct the numbers if needed, then verify or reject." />
      <Chips value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[['pending', 'Pending'], ['verified', 'Verified'], ['rejected', 'Rejected']]} />
      <Async state={state}>
        {(p) => (
          <div className="stack">
            {p.data.length === 0 && <div className="card"><Empty>Nothing to review.</Empty></div>}
            {p.data.map((s) => <ReviewCard key={s.id} s={s} onDone={state.reload} />)}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}

function ReviewCard({ s, onDone }) {
  const pending = s.status === 'pending';
  // Staff may correct what they actually weighed. Keyed by item id.
  const [edit, setEdit] = useState(() => Object.fromEntries(s.items.map((i) => [i.id, { weight_kg: String(i.weight_kg), waste_condition: i.condition }])));
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [run, busy, error] = useAction();
  const patch = (id, v) => setEdit({ ...edit, [id]: { ...edit[id], ...v } });

  const verify = () => run(async () => {
    await api(`/staff/submissions/${s.id}/verify`, {
      method: 'POST',
      body: { items: s.items.map((i) => ({ id: i.id, weight_kg: Number(edit[i.id].weight_kg), waste_condition: edit[i.id].waste_condition })) },
    });
    onDone();
  });
  const reject = () => run(async () => {
    await api(`/staff/submissions/${s.id}/reject`, { method: 'POST', body: { reason } });
    onDone();
  });

  return (
    <div className="card">
      <div className="row" style={{ paddingTop: 0 }}>
        <div className="grow"><b>{s.user?.name}</b><span className="muted small">{s.user?.group || 'No group'} · {dateTime(s.submitted_at)}</span></div>
        <Pill status={s.status} />
      </div>
      {s.notes && <p className="muted small">Note: {s.notes}</p>}
      {s.items.map((i) => (
        <div className="row" key={i.id}>
          <span className="ic">{iconFor(i.category.name)}</span>
          <b className="grow">{i.category.name}</b>
          {pending ? (
            <>
              <div className="field" style={{ margin: 0, width: 90 }}><input type="number" step="0.1" min="0.1" value={edit[i.id].weight_kg} onChange={(e) => patch(i.id, { weight_kg: e.target.value })} aria-label="Weight in kg" /></div>
              <div className="field" style={{ margin: 0 }}>
                <select value={edit[i.id].waste_condition} onChange={(e) => patch(i.id, { waste_condition: e.target.value })} aria-label="Condition">
                  <option value="clean">Clean</option><option value="contaminated">Contaminated</option>
                </select>
              </div>
            </>
          ) : <span className="muted">{i.weight_kg} kg · {i.condition}{i.points_awarded != null ? ` · +${i.points_awarded} pts` : ''}</span>}
        </div>
      ))}
      {s.reject_reason && <p className="muted small">Rejected: {s.reject_reason}</p>}
      <ErrorBox error={error} />
      {pending && (rejecting ? (
        <div style={{ marginTop: 10 }}>
          <div className="field"><label>Reason (the student will see this)</label><input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Not sorted properly" /></div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn danger sm" disabled={busy || !reason.trim()} onClick={reject}>Reject drop-off</button>
            <button className="btn ghost sm" onClick={() => setRejecting(false)}>Cancel</button>
          </div>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
          <button className="btn sm" disabled={busy} onClick={verify}>{busy ? 'Saving…' : 'Verify and award points'}</button>
          <button className="btn ghost sm" onClick={() => setRejecting(true)}>Reject</button>
        </div>
      ))}
    </div>
  );
}
