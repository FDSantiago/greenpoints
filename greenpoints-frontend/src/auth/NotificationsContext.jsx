import { createContext, useContext, useEffect, useState } from 'react';
import { stream } from '../lib/api.js';
import { useAuth } from './AuthContext.jsx';

const NotificationsCtx = createContext(null);
// const { unread, latest, toast, dismissToast } = useNotifications()
//   unread: count for the bell badge
//   latest: the newest notification pushed live (null until one arrives); pages can watch it to refetch
//   toast:  same as latest, but cleared after a few seconds (for the pop-up)
export const useNotifications = () => useContext(NotificationsCtx);

const sleep = (ms, signal) => new Promise((resolve) => {
  const t = setTimeout(resolve, ms);
  signal.addEventListener('abort', () => { clearTimeout(t); resolve(); }, { once: true });
});

export function NotificationsProvider({ children }) {
  const { user } = useAuth();
  const [unread, setUnread] = useState(0);
  const [latest, setLatest] = useState(null);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    setToast(latest);
    if (!latest) return;
    const t = setTimeout(() => setToast(null), 6000);
    return () => clearTimeout(t);
  }, [latest]);

  // One live stream (GET /notifications/stream) while signed in. The server closes it every ~minute,
  // so reconnect, passing the last id seen so nothing created in between is missed.
  // Hidden tabs disconnect: every open stream holds a PHP worker on the server.
  useEffect(() => {
    if (!user) { setUnread(0); setLatest(null); return; }
    let lastEventId = null;
    let ctrl = null;

    async function run(signal) {
      let retry = 3000;
      let failures = 0;
      while (!signal.aborted) {
        try {
          await stream('/notifications/stream', {
            lastEventId,
            signal,
            onRetry: (ms) => { retry = ms; },
            onEvent: ({ event, data, id }) => {
              failures = 0;
              if (id) lastEventId = id; // the server's cursor: resume from here after a reconnect
              if (event === 'unread') setUnread(data.unread);
              if (event === 'notification') setLatest(data);
            },
          });
        } catch (e) {
          if (signal.aborted || e.status === 401) return; // logged out: AuthContext takes over
          failures += 1;
        }
        // Back off while the server is unreachable (3 s, 6 s, 12 s ... up to a minute).
        await sleep(Math.min(retry * 2 ** failures, 60000), signal);
      }
    }

    const connect = () => {
      ctrl?.abort();
      ctrl = new AbortController();
      run(ctrl.signal);
    };
    const onVisibility = () => (document.hidden ? ctrl?.abort() : connect());

    if (!document.hidden) connect();
    document.addEventListener('visibilitychange', onVisibility);
    return () => { document.removeEventListener('visibilitychange', onVisibility); ctrl?.abort(); };
  }, [user?.id]);

  return <NotificationsCtx.Provider value={{ unread, latest, toast, dismissToast: () => setToast(null) }}>{children}</NotificationsCtx.Provider>;
}
