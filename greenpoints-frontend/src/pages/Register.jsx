import { useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { ErrorBox, Field } from '../components/ui.jsx';
import { homeFor } from './Login.jsx';

// The student ID is checked against the School Registry (System 2): the backend takes the
// student's name, course and class group (their section) from there.
export default function Register() {
  const { user, register } = useAuth();
  const nav = useNavigate();
  const [form, setForm] = useState({ student_id: '', name: '', email: '', password: '', password_confirmation: '' });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  if (user) return <Navigate to={homeFor(user)} replace />;

  // A new account is signed in straight away but must enter the emailed code first.
  async function submit(e) {
    e.preventDefault();
    setBusy(true); setError(null);
    try { const u = await register(form); nav(homeFor(u), { replace: true }); }
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
          <Field label="Student ID" required placeholder="2026-00123" pattern="\d{4}-\d{5}" title="Format: 2026-00123" value={form.student_id} onChange={set('student_id')} />
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
