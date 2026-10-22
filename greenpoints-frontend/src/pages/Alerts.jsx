import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, PageHead, Pager } from '../components/ui.jsx';
import { dateTime } from '../lib/format.js';

// Where each notification type should take you when tapped.
const target = (l) => (!l ? null : l.type === 'submission' ? `/history/${l.id}` : l.type === 'redemption' ? '/pickups' : '/wallet');

export default function Alerts() {
  const nav = useNavigate();
  const [status, setStatus] = useState('all');
  const [page, setPage] = useState(1);
  const state = useApi('/notifications', { status, page });

  async function open(n) {
    if (!n.read) await api(`/notifications/${n.id}/read`, { method: 'POST' });
    const to = target(n.link);
    if (to) nav(to); else state.reload();
  }
  async function readAll() { await api('/notifications/read-all', { method: 'POST' }); state.reload(); }
  async function remove(e, n) { e.stopPropagation(); await api(`/notifications/${n.id}`, { method: 'DELETE' }); state.reload(); }

  return (
    <>
      <PageHead title="Alerts" sub="Updates about your drop-offs, points and rewards.">
        <button className="btn sm" onClick={readAll}>Mark all read</button>
      </PageHead>
      <Chips value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[['all', 'All'], ['unread', 'Unread']]} />
      <Async state={state}>
        {(p) => (
          <div style={{ maxWidth: 720 }}>
            {p.data.length === 0 && <Empty>You are all caught up.</Empty>}
            {p.data.map((n) => (
              // n.style is "info" | "alert" | "goal": it maps straight to a CSS class
              <div key={n.id} role="button" tabIndex={0} className={`note ${n.style} ${n.read ? 'read' : ''}`} onClick={() => open(n)} onKeyDown={(e) => e.key === 'Enter' && open(n)}>
                <div style={{ flex: 1 }}><b>{n.title}</b><span className="small">{n.body}</span><div className="small" style={{ opacity: .7 }}>{dateTime(n.created_at)}</div></div>
                <button className="chip" onClick={(e) => remove(e, n)} aria-label="Dismiss">✕</button>
              </div>
            ))}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}
