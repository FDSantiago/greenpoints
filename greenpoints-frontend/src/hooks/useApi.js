import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';

// GET data for a page.  const { data, error, loading, reload } = useApi('/wallet', { page })
// It re-fetches whenever the path or params change, and ignores stale responses.
export function useApi(path, params, { enabled = true } = {}) {
  const [state, setState] = useState({ data: null, error: null, loading: enabled });
  const [tick, setTick] = useState(0);
  const key = JSON.stringify(params || {});

  useEffect(() => {
    if (!enabled || !path) return;
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    api(path, { params })
      .then((data) => !cancelled && setState({ data, error: null, loading: false }))
      .catch((error) => !cancelled && setState({ data: null, error, loading: false }));
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, key, tick, enabled]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  return { ...state, reload };
}
