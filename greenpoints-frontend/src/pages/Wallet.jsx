import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, PageHead, Pager } from '../components/ui.jsx';
import { date, pts } from '../lib/format.js';

export default function Wallet() {
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const wallet = useApi('/wallet');
  const tx = useApi('/wallet/transactions', { type, page, per_page: 10 });

  return (
    <>
      <PageHead title="My points" sub="Your balance is the sum of every row below." />
      <Async state={wallet}>
        {({ data: w }) => (
          <section className="hero" style={{ marginBottom: 16 }}>
            <div className="label">Balance</div>
            <div className="big">{pts(w.balance)}<small>pts</small></div>
            <div className="label">+{pts(w.earned_this_month)} earned this month</div>
            {w.next_reward && <><div className="meter"><i style={{ width: `${w.next_reward.progress_percent}%` }} /></div><div className="label">{pts(w.next_reward.points_needed)} pts to {w.next_reward.name}</div></>}
            <div className="row-actions"><Link className="btn light" to="/rewards">Spend points</Link></div>
          </section>
        )}
      </Async>
      <Chips value={type} onChange={(v) => { setType(v); setPage(1); }} options={[['', 'All'], ['earned', 'Earned'], ['redeemed', 'Spent']]} />
      <Async state={tx}>
        {(p) => (
          <div className="card">
            {p.data.length ? p.data.map((t) => {
              const row = (
                <>
                  <span className="ic">{t.points > 0 ? '🌱' : '🎁'}</span>
                  <div className="grow"><b>{t.note}</b><span className="muted small">{date(t.created_at)}{t.waste ? ` · ${t.waste.category} ${t.waste.weight_kg} kg` : ''}</span></div>
                  <b style={{ color: t.points > 0 ? 'var(--green)' : 'var(--red)' }}>{t.points > 0 ? '+' : ''}{t.points}</b>
                </>
              );
              return t.waste
                ? <Link className="row" key={t.id} to={`/history/${t.waste.submission_id}`}>{row}</Link>
                : <div className="row" key={t.id}>{row}</div>;
            }) : <Empty>No transactions yet.</Empty>}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}
