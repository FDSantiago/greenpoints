import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { ErrorBox, Field } from '../components/ui.jsx';

// The API also accepts group_id, but there is no public endpoint that lists groups,
// so new students start without a group and an admin assigns one (Admin > Users).
export default function Register() {
  const { user, register } = useAuth();
  const nav = useNavigate();
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  if (user) return <Navigate to="/" replace />;

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setError(null);
    try { await register(form); nav('/', { replace: true }); }
    catch (err) { setError(err); } finally { setBusy(false); }
  }

  return (
    <div className="auth">
      <div className="box">
        <img src="/logo.png" alt="GreenPoints" />
        <h1>Join GreenPoints</h1>
        <p className="muted">Recycle, earn points, redeem rewards.</p>
        <form onSubmit={submit}>
          <ErrorBox error={error} />
          <Field label="Full name" required value={form.name} onChange={set('name')} />
          <Field label="Email" type="email" required value={form.email} onChange={set('email')} />
          <Field label="Password (8+ characters)" type="password" required minLength={8} value={form.password} onChange={set('password')} />
          <Field label="Confirm password" type="password" required value={form.password_confirmation} onChange={set('password_confirmation')} />
          <button className="btn block" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button>
        </form>
        <p className="small muted" style={{ marginTop: 16 }}>Already have an account? <Link to="/login" style={{ color: 'var(--green)', fontWeight: 800 }}>Log in</Link></p>
      </div>
    </div>
  );
}
