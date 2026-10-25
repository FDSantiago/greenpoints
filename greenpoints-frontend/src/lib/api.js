// One place that talks to Laravel. Every page uses api() or useApi(), never fetch() directly.
const BASE = import.meta.env.VITE_API_URL || '/api/v1';
const TOKEN_KEY = 'gp_token';

export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY));

// Laravel validation errors look like { message, errors: { field: ["msg"] } }.
// School Registry failures (503) look like { error: { code: "REGISTRY_UNAVAILABLE", message } }.
export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || body?.error?.message || `Request failed (${status})`);
    this.status = status;
    this.code = body?.error?.code || null;
    this.errors = body?.errors || null;
  }
  // All field messages as one flat list, handy for showing under a form.
  get details() {
    return this.errors ? Object.values(this.errors).flat() : [];
  }
}

export async function api(path, { method = 'GET', body, params, headers = {}, blob = false } = {}) {
  const url = new URL(BASE + path, window.location.origin);
  // Skip empty filters so ?status= never reaches the server.
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  });

  const token = getToken();
  const res = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  if (res.status === 401 && token) {
    // Token expired or revoked: tell AuthContext to log out.
    setToken(null);
    window.dispatchEvent(new Event('gp:unauthorized'));
  }
  if (!res.ok) {
    let data = null;
    try { data = await res.json(); } catch { /* empty body */ }
    throw new ApiError(res.status, data);
  }
  if (res.status === 204) return null; // DELETE responses have no body
  return blob ? res.blob() : res.json();
}
