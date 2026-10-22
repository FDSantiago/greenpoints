import { useState } from 'react';
import { api } from '../../lib/api.js';
import { useApi } from '../../hooks/useApi.js';
import { Async, ErrorBox, Field, Modal, PageHead, Pager, Pill, useAction } from '../../components/ui.jsx';
import { cap } from '../../lib/format.js';

export default function Users() {
  const [q, setQ] = useState('');
  const [role, setRole] = useState('');
  const [page, setPage] = useState(1);
  const users = useApi('/admin/users', { q, role, page });
  const groups = useApi('/admin/groups');
  const [modal, setModal] = useState(null); // { kind: 'edit' | 'points', user? }
  const [run, , error] = useAction();

  async function toggleActive(u) {
    await run(async () => { await api(`/admin/users/${u.id}`, { method: 'PATCH', body: { is_active: !u.is_active } }); users.reload(); });
  }
  async function remove(u) {
    if (!confirm(`Delete ${u.name}? This only works for users with no activity.`)) return;
    await run(async () => { await api(`/admin/users/${u.id}`, { method: 'DELETE' }); users.reload(); });
  }
  const close = (changed) => { setModal(null); if (changed) users.reload(); };

  return (
    <>
      <PageHead title="Users" sub="Students, staff and admins.">
        <button className="btn sm" onClick={() => setModal({ kind: 'edit' })}>Add user</button>
      </PageHead>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
        <div className="field" style={{ margin: 0, flex: '1 1 220px' }}><input placeholder="Search name or email" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} aria-label="Search users" /></div>
        <div className="field" style={{ margin: 0 }}>
          <select value={role} onChange={(e) => { setRole(e.target.value); setPage(1); }} aria-label="Role">
            <option value="">All roles</option><option value="student">Students</option><option value="staff">Staff</option><option value="admin">Admins</option>
          </select>
        </div>
      </div>
      <ErrorBox error={error} />
      <Async state={users}>
        {(p) => (
          <div className="card flush">
            <div className="tablewrap"><table>
              <thead><tr><th>Name</th><th>Role</th><th>Group</th><th>Points</th><th>Status</th><th></th></tr></thead>
              <tbody>
                {p.data.map((u) => (
                  <tr key={u.id}>
                    <td><b>{u.name}</b><div className="muted small">{u.email}</div></td>
                    <td>{cap(u.role)}</td>
                    <td>{u.group?.name || '–'}</td>
                    <td>{u.points_balance ?? '–'}</td>
                    <td><Pill status={u.is_active ? 'verified' : 'off'} /></td>
                    <td style={{ whiteSpace: 'nowrap' }}>
                      <button className="btn ghost sm" onClick={() => setModal({ kind: 'edit', user: u })}>Edit</button>{' '}
                      {u.role === 'student' && <button className="btn ghost sm" onClick={() => setModal({ kind: 'points', user: u })}>Points</button>}{' '}
                      <button className="btn ghost sm" onClick={() => toggleActive(u)}>{u.is_active ? 'Deactivate' : 'Activate'}</button>{' '}
                      <button className="btn ghost sm" onClick={() => remove(u)}>Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table></div>
          </div>
        )}
      </Async>
      <Pager data={users.data} onPage={setPage} />
      {modal?.kind === 'edit' && <UserForm user={modal.user} groups={groups.data?.data || []} onClose={close} />}
      {modal?.kind === 'points' && <PointsForm user={modal.user} onClose={close} />}
    </>
  );
}

function UserForm({ user, groups, onClose }) {
  const editing = !!user;
  const [f, setF] = useState({ name: user?.name || '', email: user?.email || '', password: '', role: user?.role || 'student', group_id: user?.group?.id || '' });
  const [run, busy, error] = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  const save = (e) => { e.preventDefault(); run(async () => {
    const body = { ...f, group_id: f.group_id ? Number(f.group_id) : null };
    if (!body.password) delete body.password; // blank on edit = keep the old one
    await api(editing ? `/admin/users/${user.id}` : '/admin/users', { method: editing ? 'PATCH' : 'POST', body });
    onClose(true);
  }); };

  return (
    <Modal title={editing ? 'Edit user' : 'Add user'} onClose={() => onClose(false)}>
      <form onSubmit={save}>
        <ErrorBox error={error} />
        <Field label="Name" required value={f.name} onChange={set('name')} />
        <Field label="Email" type="email" required value={f.email} onChange={set('email')} />
        <Field label={editing ? 'New password (leave blank to keep)' : 'Password (8+ characters)'} type="password" required={!editing} minLength={8} value={f.password} onChange={set('password')} />
        <Field label="Role"><select value={f.role} onChange={set('role')}><option value="student">Student</option><option value="staff">Staff</option><option value="admin">Admin</option></select></Field>
        <Field label="Group"><select value={f.group_id} onChange={set('group_id')}><option value="">No group</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}</select></Field>
        <div className="actions"><button type="button" className="btn ghost" onClick={() => onClose(false)}>Cancel</button><button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save user'}</button></div>
      </form>
    </Modal>
  );
}

function PointsForm({ user, onClose }) {
  const [points, setPoints] = useState('');
  const [note, setNote] = useState('');
  const [run, busy, error] = useAction();
  const save = (e) => { e.preventDefault(); run(async () => {
    await api(`/admin/users/${user.id}/points-adjustments`, { method: 'POST', body: { points: Number(points), note } });
    onClose(true);
  }); };
  return (
    <Modal title={`Adjust points for ${user.name}`} onClose={() => onClose(false)}>
      <form onSubmit={save}>
        <ErrorBox error={error} />
        <p className="muted small" style={{ marginBottom: 10 }}>Current balance: {user.points_balance}. Use a negative number to remove points.</p>
        <Field label="Points (e.g. 50 or -20)" type="number" required value={points} onChange={(e) => setPoints(e.target.value)} />
        <Field label="Reason" required value={note} onChange={(e) => setNote(e.target.value)} />
        <div className="actions"><button type="button" className="btn ghost" onClick={() => onClose(false)}>Cancel</button><button className="btn" disabled={busy || !Number(points)}>Apply</button></div>
      </form>
    </Modal>
  );
}
