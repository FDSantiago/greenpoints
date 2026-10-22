import { useState } from 'react';
import { api } from '../../lib/api.js';
import { useApi } from '../../hooks/useApi.js';
import { Async, ErrorBox, Field, Modal, PageHead, Pill, useAction } from '../../components/ui.jsx';

// ONE generic screen drives three admin pages. Each page is only a config object:
//   path   – the API collection, e.g. /admin/groups
//   fields – the form inputs (type: text | number | select | checkbox)
//   cols   – the table columns
function CrudPage({ title, sub, noun, path, fields, cols, blank }) {
  const state = useApi(path);
  const [editing, setEditing] = useState(null); // null | {} (new) | existing row
  const [run, , error] = useAction();

  const remove = (row) => {
    if (!confirm(`Delete "${row.name}"?`)) return;
    // The server answers 409 when something already uses this row, and says to deactivate instead.
    run(async () => { await api(`${path}/${row.id}`, { method: 'DELETE' }); state.reload(); });
  };

  return (
    <>
      <PageHead title={title} sub={sub}><button className="btn sm" onClick={() => setEditing({ ...blank })}>Add {noun}</button></PageHead>
      <ErrorBox error={error} />
      <Async state={state}>
        {({ data }) => (
          <div className="card flush"><div className="tablewrap"><table>
            <thead><tr>{cols.map((c) => <th key={c.label}>{c.label}</th>)}<th></th></tr></thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.id}>
                  {cols.map((c) => <td key={c.label}>{c.render ? c.render(row) : row[c.key]}</td>)}
                  <td style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
                    <button className="btn ghost sm" onClick={() => setEditing(row)}>Edit</button>{' '}
                    <button className="btn ghost sm" onClick={() => remove(row)}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table></div></div>
        )}
      </Async>
      {editing && <CrudForm noun={noun} path={path} fields={fields} row={editing} onClose={(changed) => { setEditing(null); if (changed) state.reload(); }} />}
    </>
  );
}

function CrudForm({ noun, path, fields, row, onClose }) {
  const isNew = !row.id;
  const [f, setF] = useState(() => Object.fromEntries(fields.map((x) => [x.name, row[x.name] ?? (x.type === 'checkbox' ? true : '')])));
  const [run, busy, error] = useAction();

  const save = (e) => { e.preventDefault(); run(async () => {
    // Convert form strings into the types the API expects.
    const body = Object.fromEntries(fields.map((x) => {
      const v = f[x.name];
      return [x.name, x.type === 'number' ? Number(v) : ['text', 'url'].includes(x.type) && !x.required && v === '' ? null : v];
    }));
    await api(isNew ? path : `${path}/${row.id}`, { method: isNew ? 'POST' : 'PATCH', body });
    onClose(true);
  }); };

  return (
    <Modal title={`${isNew ? 'Add' : 'Edit'} ${noun}`} onClose={() => onClose(false)}>
      <form onSubmit={save}>
        <ErrorBox error={error} />
        {fields.map((x) =>
          x.type === 'checkbox' ? (
            <label className="check" key={x.name}><input type="checkbox" checked={!!f[x.name]} onChange={(e) => setF({ ...f, [x.name]: e.target.checked })} />{x.label}</label>
          ) : x.type === 'select' ? (
            <Field key={x.name} label={x.label}><select value={f[x.name]} onChange={(e) => setF({ ...f, [x.name]: e.target.value })}>{x.options.map((o) => <option key={o} value={o}>{o}</option>)}</select></Field>
          ) : (
            <Field key={x.name} label={x.label} type={x.type} required={x.required} step={x.step} min={x.min} value={f[x.name]} onChange={(e) => setF({ ...f, [x.name]: e.target.value })} />
          )
        )}
        <div className="actions"><button type="button" className="btn ghost" onClick={() => onClose(false)}>Cancel</button><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div>
      </form>
    </Modal>
  );
}

const active = (r) => <Pill status={r.is_active ? 'verified' : 'off'} />;

export const Groups = () => (
  <CrudPage title="Groups" sub="Classes or zones that compete on the leaderboard." noun="group" path="/admin/groups"
    blank={{ type: 'class' }}
    fields={[{ name: 'name', label: 'Name', type: 'text', required: true }, { name: 'type', label: 'Type', type: 'select', options: ['class', 'zone'] }]}
    cols={[{ label: 'Name', key: 'name' }, { label: 'Type', key: 'type' }, { label: 'Members', key: 'members_count' }]} />
);

export const Categories = () => (
  <CrudPage title="Waste types" sub="Points per kilogram for each kind of waste. Changes only affect future drop-offs." noun="waste type" path="/admin/waste-categories"
    blank={{ is_recyclable: true, is_active: true }}
    fields={[
      { name: 'name', label: 'Name', type: 'text', required: true },
      { name: 'points_per_kg', label: 'Points per kg', type: 'number', required: true, step: '0.5', min: 0 },
      { name: 'is_recyclable', label: 'Recyclable', type: 'checkbox' },
      { name: 'is_active', label: 'Accepting drop-offs', type: 'checkbox' },
    ]}
    cols={[{ label: 'Name', key: 'name' }, { label: 'Pts / kg', key: 'points_per_kg' }, { label: 'Recyclable', render: (r) => (r.is_recyclable ? 'Yes' : 'No') }, { label: 'Used', key: 'logs_count' }, { label: 'Status', render: active }]} />
);

export const AdminRewards = () => (
  <CrudPage title="Rewards" sub="What students can redeem. Changes only affect future redemptions." noun="reward" path="/admin/rewards"
    blank={{ is_active: true, stock_qty: 10 }}
    fields={[
      { name: 'name', label: 'Name', type: 'text', required: true },
      { name: 'description', label: 'Description', type: 'text' },
      { name: 'image_url', label: 'Image link (optional)', type: 'url' },
      { name: 'points_cost', label: 'Points cost', type: 'number', required: true, min: 1 },
      { name: 'stock_qty', label: 'Stock', type: 'number', required: true, min: 0 },
      { name: 'is_active', label: 'Visible to students', type: 'checkbox' },
    ]}
    cols={[{ label: 'Name', key: 'name' }, { label: 'Cost', key: 'points_cost' }, { label: 'Stock', key: 'stock_qty' }, { label: 'Redeemed', key: 'redemptions_count' }, { label: 'Status', render: active }]} />
);
