import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../lib/api.js';
import { useApi } from '../hooks/useApi.js';
import { Async, ErrorBox, PageHead, useAction } from '../components/ui.jsx';
import { iconFor } from '../lib/format.js';

export default function DropOff() {
  const nav = useNavigate();
  const cats = useApi('/waste-categories');
  // picked = { [categoryId]: { weight: '1.2', condition: 'clean' } }
  const [picked, setPicked] = useState({});
  const [notes, setNotes] = useState('');
  const [run, busy, error] = useAction();

  const toggle = (id) =>
    setPicked((p) => {
      const next = { ...p };
      if (next[id]) delete next[id]; else next[id] = { weight: '1', condition: 'clean' };
      return next;
    });
  const update = (id, patch) => setPicked((p) => ({ ...p, [id]: { ...p[id], ...patch } }));

  return (
    <>
      <PageHead title="Drop off waste" sub="Pick what you brought. Staff confirm the final weight before points are added." />
      <Async state={cats}>
        {({ data: list, condition_multipliers: mult }) => {
          const rows = Object.entries(picked).map(([id, v]) => ({ cat: list.find((c) => c.id === Number(id)), ...v }));
          // Same formula as the server, just for a live preview. The server always recalculates.
          const estimate = rows.reduce((sum, r) => sum + Math.round((Number(r.weight) || 0) * r.cat.points_per_kg * mult[r.condition]), 0);
          const valid = rows.length > 0 && rows.every((r) => Number(r.weight) > 0 && Number(r.weight) <= 100);

          const submit = () => run(async () => {
            const res = await api('/waste-submissions', {
              method: 'POST',
              body: {
                notes: notes || null,
                items: rows.map((r) => ({ category_id: r.cat.id, weight_kg: Number(r.weight), waste_condition: r.condition })),
              },
            });
            nav(`/history/${res.data.id}`);
          });

          return (
            <div className="cols two">
              <div className="stack">
                <div className="card">
                  <h2>Waste type</h2>
                  <div className="cats">
                    {list.map((c) => (
                      <button key={c.id} className="cat" aria-pressed={!!picked[c.id]} onClick={() => toggle(c.id)}>
                        <span className="e">{iconFor(c.name)}</span><b>{c.name}</b>
                        <span className="muted small">{c.points_per_kg} pts per kg</span>
                      </button>
                    ))}
                  </div>
                </div>
                <div className="card">
                  <h2>Note for staff (optional)</h2>
                  <div className="field"><textarea rows={2} maxLength={500} placeholder="Example: Grade 11, room 204" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
                </div>
              </div>

              <div className="card" style={{ alignSelf: 'start' }}>
                <h2>Your drop-off</h2>
                {!rows.length && <p className="muted">Choose a waste type to begin.</p>}
                {rows.map((r) => (
                  <div className="row" key={r.cat.id} style={{ flexWrap: 'wrap' }}>
                    <span className="ic">{iconFor(r.cat.name)}</span>
                    <b className="grow">{r.cat.name}</b>
                    <div className="field" style={{ margin: 0, width: 100 }}>
                      <input type="number" min="0.1" max="100" step="0.1" value={r.weight} aria-label={`${r.cat.name} weight in kg`} onChange={(e) => update(r.cat.id, { weight: e.target.value })} />
                    </div>
                    <span className="muted">kg</span>
                    <div className="chips" style={{ margin: 0, width: '100%' }}>
                      {Object.keys(mult).map((c) => (
                        <button key={c} className="chip" aria-pressed={r.condition === c} onClick={() => update(r.cat.id, { condition: c })}>
                          {c === 'clean' ? 'Clean' : `Contaminated (×${mult[c]})`}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
                <div className="row" style={{ justifyContent: 'space-between' }}>
                  <span className="muted">Estimated points</span><b style={{ color: 'var(--green)', fontSize: 26 }}>{estimate}</b>
                </div>
                <ErrorBox error={error} />
                <button className="btn block" disabled={!valid || busy} onClick={submit}>{busy ? 'Sending…' : 'Send for staff check'}</button>
              </div>
            </div>
          );
        }}
      </Async>
    </>
  );
}
