import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, PageHead } from '../components/ui.jsx';
import { pts } from '../lib/format.js';

export default function Rewards() {
  const [affordable, setAffordable] = useState('');
  const state = useApi('/rewards', { affordable });
  return (
    <>
      <PageHead title="Rewards" sub="Good habits. Great little rewards.">
        <Link className="btn ghost sm" to="/pickups">My reward pickups</Link>
      </PageHead>
      <Async state={state}>
        {({ balance, data }) => (
          <>
            <p style={{ marginBottom: 12, fontWeight: 800 }}>You have <span style={{ color: 'var(--green)' }}>{pts(balance)} pts</span></p>
            <Chips value={affordable} onChange={setAffordable} options={[['', 'All rewards'], ['true', 'I can afford']]} />
            {data.length === 0 && <Empty>No rewards match yet. Keep recycling!</Empty>}
            <div className="grid3">
              {data.map((r) => (
                <div className="card flush reward" key={r.id}>
                  <div className="pic">{r.image_url ? <img src={r.image_url} alt="" /> : '🎁'}</div>
                  <div className="body">
                    <b>{r.name}</b>
                    <span className="muted small">{r.description}</span>
                    <span className="cost">{pts(r.points_cost)} pts</span>
                    <span className="muted small">{r.in_stock ? `${r.stock_qty} left` : 'Out of stock'}</span>
                    {r.in_stock && r.can_afford
                      ? <Link className="btn sm block" to={`/rewards/${r.id}`}>Redeem</Link>
                      : <button className="btn sm block" disabled>{r.in_stock ? `Need ${pts(r.points_cost - balance)} more` : 'Out of stock'}</button>}
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </Async>
    </>
  );
}
