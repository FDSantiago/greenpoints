import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../lib/api.js';
import { useApi } from '../hooks/useApi.js';
import { Async, ErrorBox, useAction } from '../components/ui.jsx';
import { pts } from '../lib/format.js';

export default function RewardDetail() {
  const { id } = useParams();
  const state = useApi(`/rewards/${id}`);
  const [run, busy, error] = useAction();
  const [done, setDone] = useState(null); // the redemption, after success
  // One Idempotency-Key per button press. If the network drops and the user taps again,
  // the SAME key is sent, so the server never charges twice.
  const key = useRef(null);

  const redeem = () => run(async () => {
    key.current ??= crypto.randomUUID();
    const res = await api(`/rewards/${id}/redeem`, { method: 'POST', headers: { 'Idempotency-Key': key.current } });
    key.current = null;
    setDone(res);
  });

  return (
    <>
      <p style={{ marginBottom: 10 }}><Link to="/rewards" className="muted" style={{ fontWeight: 800 }}>← Back to rewards</Link></p>
      <Async state={state}>
        {({ data: r }) => (
          <div className="card" style={{ maxWidth: 560 }}>
            <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
              <div className="ic" style={{ width: 64, height: 64, fontSize: 32 }}>{r.image_url ? <img src={r.image_url} alt="" style={{ width: '100%', borderRadius: 12 }} /> : '🎁'}</div>
              <div><h1 style={{ fontSize: 22 }}>{r.name}</h1><p className="muted">{r.description}</p></div>
            </div>

            {done ? (
              <div style={{ marginTop: 18 }}>
                <div className="alert ok">Redeemed! Your new balance is {pts(done.balance_after)} pts.</div>
                <p className="muted small">Show this code at pickup</p>
                <div style={{ fontSize: 34, fontWeight: 900, letterSpacing: '.12em', color: 'var(--green)' }}>{done.data.code}</div>
                <p className="muted" style={{ margin: '8px 0 16px' }}>{done.data.pickup.location}. {done.data.pickup.note}</p>
                <Link className="btn" to="/pickups">View my pickups</Link>
              </div>
            ) : (
              <>
                <div style={{ margin: '18px 0' }}>
                  <div className="row"><span className="grow muted">Your balance</span><b>{pts(r.preview.balance)} pts</b></div>
                  <div className="row"><span className="grow muted">Cost</span><b>−{pts(r.preview.cost)} pts</b></div>
                  <div className="row"><span className="grow muted">Balance after</span><b style={{ color: 'var(--green)' }}>{pts(r.preview.balance_after)} pts</b></div>
                </div>
                <p className="muted small" style={{ marginBottom: 12 }}>{r.preview.pickup.location}. {r.preview.pickup.note}</p>
                <ErrorBox error={error} />
                <button className="btn block" disabled={busy || !r.preview.can_afford || !r.in_stock} onClick={redeem}>
                  {busy ? 'Redeeming…' : r.preview.can_afford ? 'Confirm redemption' : 'Not enough points'}
                </button>
              </>
            )}
          </div>
        )}
      </Async>
    </>
  );
}
