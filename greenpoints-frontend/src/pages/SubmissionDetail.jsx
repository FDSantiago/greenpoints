import { Link, useParams } from 'react-router-dom';
import { useApi } from '../hooks/useApi.js';
import { Async, PageHead, Pill } from '../components/ui.jsx';
import { dateTime, iconFor, kg } from '../lib/format.js';

export default function SubmissionDetail() {
  const { id } = useParams();
  const state = useApi(`/waste-submissions/${id}`);
  return (
    <>
      <p style={{ marginBottom: 10 }}><Link to="/history" className="muted" style={{ fontWeight: 800 }}>← Back to history</Link></p>
      <Async state={state}>
        {({ data: s }) => (
          <>
            <PageHead title="Drop-off details" sub={`Sent ${dateTime(s.submitted_at)}`}><Pill status={s.status} /></PageHead>
            {s.status === 'rejected' && <div className="alert">Rejected: {s.reject_reason}</div>}
            {s.status === 'verified' && <div className="alert ok">Verified {dateTime(s.verified_at)}. You earned {s.points_awarded} points.</div>}
            {s.status === 'pending' && <div className="alert" style={{ background: '#fff2cc', color: '#6a4b00' }}>Waiting for staff to check the weight.</div>}
            <div className="card">
              {s.items.map((i) => (
                <div className="row" key={i.id}>
                  <span className="ic">{iconFor(i.category.name)}</span>
                  <div className="grow"><b>{i.category.name}</b><span className="muted small">{kg(i.weight_kg)} · {i.condition}</span></div>
                  <b>{i.points_awarded != null ? `+${i.points_awarded} pts` : '–'}</b>
                </div>
              ))}
              <div className="row"><b className="grow">Total</b><b>{kg(s.total_weight_kg)}</b></div>
              {s.notes && <p className="muted small">Your note: {s.notes}</p>}
            </div>
          </>
        )}
      </Async>
    </>
  );
}
