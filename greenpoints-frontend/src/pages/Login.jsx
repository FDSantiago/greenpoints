import { useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { ErrorBox, Field } from '../components/ui.jsx';

export const homeFor = (u) =>
  !u.email_verified ? '/verify-email' : u.role === 'admin' ? '/admin' : u.role === 'staff' ? '/staff/review' : '/';

export default function Login() {
  const { user, login } = useAuth();
  const nav = useNavigate();
  const from = useLocation().state?.from;
  const [form, setForm] = useState({ email: '', password: '' });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  if (user) return <Navigate to={homeFor(user)} replace />;

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const u = await login(form.email, form.password);
      nav(u.email_verified ? from || homeFor(u) : homeFor(u), { replace: true });
    } catch (err) { setError(err); } finally { setBusy(false); }
  }

  return (
    <div className="auth">
      <div className="box">
        <img src="/logo.png" alt="GreenPoints" />
        <h1>Welcome back</h1>
        <p className="muted">Log in to track your recycling.</p>
        <form onSubmit={submit}>
          <ErrorBox error={error} />
          <Field label="Email" type="email" required autoComplete="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Field label="Password" type="password" required autoComplete="current-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          <button className="btn block" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</button>
        </form>
        <p className="small muted" style={{ marginTop: 16 }}>New here? <Link to="/register" style={{ color: 'var(--green)', fontWeight: 800 }}>Create an account</Link></p>
        {import.meta.env.DEV && <p className="small muted" style={{ marginTop: 10 }}>Dev logins: student1@greenpoints.test, staff@greenpoints.test, admin@greenpoints.test · password</p>}
      </div>
    </div>
  );
}
