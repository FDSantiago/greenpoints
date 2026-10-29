import { useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { api } from '../lib/api.js';
import { Field, Spinner } from '../components/ui.jsx';
import { homeFor } from './Login.jsx';

// Signed in but not verified yet: this is the only screen the account can use.
// The resend cooldown comes from the server (resend_available_in / 429 retry_after);
// the countdown here is only for display, the server refuses early resends anyway.
export default function VerifyEmail() {
  const { user, ready, logout, setUser } = useAuth();
  const nav = useNavigate();
  const [code, setCode] = useState('');
  const [status, setStatus] = useState(null);
  const [availableAt, setAvailableAt] = useState(0);
  const [now, setNow] = useState(Date.now());
  const [msg, setMsg] = useState(null); // { ok, text }
  const [busy, setBusy] = useState(null); // 'verify' | 'resend'

  const unverified = !!user && !user.email_verified;
  const wait = Math.max(0, Math.ceil((availableAt - now) / 1000));

  function startCooldown(seconds) {
    setNow(Date.now());
    setAvailableAt(Date.now() + seconds * 1000);
  }

  useEffect(() => {
    if (!unverified) return;
    api('/auth/email/verification')
      .then((r) => { setStatus(r.data); startCooldown(r.data.resend_available_in); })
      .catch(() => {});
  }, [unverified]);

  // Tick once a second only while the cooldown is running.
  const counting = wait > 0;
  useEffect(() => {
    if (!counting) return undefined;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [counting]);

  if (!ready) return <Spinner full />;
  if (!user) return <Navigate to="/login" replace />;
  if (user.email_verified) return <Navigate to={homeFor(user)} replace />;

  const errText = (err) => err.errors?.code?.[0] || err.message;

  async function verify(e) {
    e.preventDefault();
    setBusy('verify'); setMsg(null);
    try {
      const r = await api('/auth/email/verify', { method: 'POST', body: { code } });
      setUser(r.user);
      nav(homeFor(r.user), { replace: true });
    } catch (err) {
      setMsg({ ok: false, text: errText(err) });
    } finally { setBusy(null); }
  }

  async function resend() {
    setBusy('resend'); setMsg(null);
    try {
      const r = await api('/auth/email/verification/resend', { method: 'POST' });
      setStatus(r.data);
      startCooldown(r.data.resend_available_in);
      setCode('');
      setMsg({ ok: true, text: `A new code was sent to ${r.data.email}.` });
    } catch (err) {
      if (err.retryAfter) startCooldown(err.retryAfter);
      setMsg({ ok: false, text: errText(err) });
    } finally { setBusy(null); }
  }

  const noCodeSent = status && !status.code_expires_at;

  return (
    <div className="auth">
      <div className="box">
        <img src="/logo.png" alt="GreenPoints" />
        <h1>Check your email</h1>
        <p className="muted">
          We sent a 6-digit code to <b>{user.email}</b>. Enter it to finish setting up your account.
        </p>
        <form onSubmit={verify}>
          {msg && <div className={`alert ${msg.ok ? 'ok' : ''}`}>{msg.text}</div>}
          {noCodeSent && !msg && <div className="alert">We couldn't send your code. Press "Send a new code" to try again.</div>}
          <Field
            label="Verification code" required inputMode="numeric" autoComplete="one-time-code"
            pattern="\d{6}" maxLength={6} title="The 6 digits from the email" placeholder="123456"
            value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
          />
          <button className="btn block" disabled={busy !== null || code.length !== 6}>
            {busy === 'verify' ? 'Verifying…' : 'Verify email'}
          </button>
        </form>
        <button className="btn ghost block" style={{ marginTop: 10 }} onClick={resend} disabled={busy !== null || wait > 0}>
          {busy === 'resend' ? 'Sending…' : wait > 0 ? `Send a new code in ${wait}s` : 'Send a new code'}
        </button>
        <p className="small muted" style={{ marginTop: 16 }}>
          Wrong account? <button onClick={logout} style={{ color: 'var(--green)', fontWeight: 800 }}>Log out</button>
        </p>
      </div>
    </div>
  );
}
