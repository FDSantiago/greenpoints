// Small building blocks reused on every page.
import { useState } from 'react';
import { cap } from '../lib/format.js';

export function Spinner({ full }) {
  return <div className={`spin ${full ? 'full' : ''}`} role="status" aria-label="Loading" />;
}

export function ErrorBox({ error, onRetry }) {
  if (!error) return null;
  return (
    <div className="alert">
      'Something went wrong.'
      {error.details?.length > 0 && <ul>{error.details.map((d) => <li key={d}>{d}</li>)}</ul>}
      {onRetry && <button className="btn sm" style={{ marginTop: 8 }} onClick={onRetry}>Try again</button>}
    </div>
  );
}

export const Empty = ({ children }) => <div className="empty">{children}</div>;
export const Pill = ({ status }) => <span className={`pill ${status}`}>{cap(status)}</span>;

export function PageHead({ title, sub, children }) {
  return (
    <div className="pagehead">
      <div>
        <h1>{title}</h1>
        {sub && <p className="muted">{sub}</p>}
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>{children}</div>
    </div>
  );
}

// Shows <Spinner/> or <ErrorBox/> until data is ready, then renders children(data).
export function Async({ state, children }) {
  if (state.loading && !state.data) return <Spinner />;
  if (state.error) return <ErrorBox error={state.error} onRetry={state.reload} />;
  return state.data ? children(state.data) : null;
}

export function Chips({ options, value, onChange }) {
  return (
    <div className="chips">
      {options.map(([v, label]) => (
        <button key={v} className="chip" aria-pressed={value === v} onClick={() => onChange(v)}>{label}</button>
      ))}
    </div>
  );
}

// Laravel's paginator gives current_page and last_page.
export function Pager({ data, onPage }) {
  if (!data || data.last_page <= 1) return null;
  return (
    <div className="pager">
      <button className="btn ghost sm" disabled={data.current_page <= 1} onClick={() => onPage(data.current_page - 1)}>Previous</button>
      <span>Page {data.current_page} of {data.last_page}</span>
      <button className="btn ghost sm" disabled={data.current_page >= data.last_page} onClick={() => onPage(data.current_page + 1)}>Next</button>
    </div>
  );
}

export function Field({ label, children, ...input }) {
  return (
    <div className="field">
      <label>{label}</label>
      {children || <input {...input} />}
    </div>
  );
}

export function Modal({ title, onClose, children }) {
  return (
    <div className="modal-wrap" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={title}>
        <h2>{title}</h2>
        {children}
      </div>
    </div>
  );
}

// Horizontal bars: items = [{ label, value }]
export function Bars({ items, unit = 'kg' }) {
  const max = Math.max(...items.map((i) => i.value), 0.0001);
  if (!items.length) return <Empty>No verified waste yet.</Empty>;
  return (
    <div className="bars">
      {items.map((i) => (
        <div className="brow" key={i.label}>
          <span>{i.label}</span>
          <div className="meter"><i style={{ width: `${(i.value / max) * 100}%` }} /></div>
          <b>{i.value} {unit}</b>
        </div>
      ))}
    </div>
  );
}

// Run an async action with loading + error state. const [run, busy, err] = useAction()
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  async function run(fn) {
    setBusy(true); setError(null);
    try { return await fn(); } catch (e) { setError(e); return undefined; } finally { setBusy(false); }
  }
  return [run, busy, error, setError];
}
