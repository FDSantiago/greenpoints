import { createContext, useContext, useEffect, useState } from 'react';
import { api, getToken, setToken } from '../lib/api.js';

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(!getToken()); // no token = nothing to wait for

  // On first load, if a token is saved, ask the server who we are.
  useEffect(() => {
    if (!getToken()) return;
    api('/me')
      .then((r) => setUser(r.data)) // /me is wrapped in { data }
      .catch(() => setToken(null))
      .finally(() => setReady(true));
  }, []);

  // api.js fires this event when the server answers 401.
  useEffect(() => {
    const h = () => setUser(null);
    window.addEventListener('gp:unauthorized', h);
    return () => window.removeEventListener('gp:unauthorized', h);
  }, []);

  async function startSession(path, body) {
    const r = await api(path, { method: 'POST', body });
    setToken(r.token);
    setUser(r.user); // login/register return { token, user } with no wrapper
    return r.user;
  }
  const login = (email, password) => startSession('/auth/login', { email, password, device_name: 'web' });
  const register = (form) => startSession('/auth/register', form);
  async function logout() {
    try { await api('/auth/logout', { method: 'POST' }); } catch { /* token may already be dead */ }
    setToken(null);
    setUser(null);
  }

  return <AuthCtx.Provider value={{ user, ready, login, register, logout }}>{children}</AuthCtx.Provider>;
}
