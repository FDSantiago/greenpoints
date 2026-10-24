// One place that talks to Laravel. Every page uses api() or useApi(), never fetch() directly.
const BASE = import.meta.env.VITE_API_URL || '/api/v1';
const TOKEN_KEY = 'gp_token';

export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY));

// Laravel validation errors look like { message, errors: { field: ["msg"] } }.
export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Request failed (${status})`);
    this.status = status;
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

// Read a server-sent events stream. EventSource can't send our Bearer token, so this uses fetch
// and parses the text/event-stream body itself. Calls onEvent({ event, data, id }) per message
// (data is parsed JSON) and resolves when the server closes the stream; abort it with `signal`.
export async function stream(path, { onEvent, onRetry, lastEventId, signal } = {}) {
  const token = getToken();
  const res = await fetch(new URL(BASE + path, window.location.origin), {
    headers: {
      Accept: 'text/event-stream',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(lastEventId ? { 'Last-Event-ID': lastEventId } : {}),
    },
    signal,
  });

  if (res.status === 401 && token) {
    setToken(null);
    window.dispatchEvent(new Event('gp:unauthorized'));
  }
  if (!res.ok) throw new ApiError(res.status, null);

  const reader = res.body.pipeThrough(new TextDecoderStream()).getReader();
  let buffer = '';
  for (;;) {
    const { value, done } = await reader.read();
    if (done) return;
    buffer += value.replace(/\r\n?/g, '\n');

    // Messages are separated by a blank line; keep the trailing partial one for the next chunk.
    const blocks = buffer.split('\n\n');
    buffer = blocks.pop();
    for (const block of blocks) {
      const msg = { event: 'message', data: '', id: null };
      for (const line of block.split('\n')) {
        if (!line || line.startsWith(':')) continue; // ": ping" keep-alives
        const i = line.indexOf(':');
        const field = i === -1 ? line : line.slice(0, i);
        const val = i === -1 ? '' : line.slice(i + 1).replace(/^ /, '');
        if (field === 'data') msg.data += (msg.data ? '\n' : '') + val;
        else if (field === 'event') msg.event = val;
        else if (field === 'id') msg.id = val;
        else if (field === 'retry' && /^\d+$/.test(val)) onRetry?.(Number(val));
      }
      if (msg.data) onEvent?.({ ...msg, data: JSON.parse(msg.data) });
    }
  }
}
