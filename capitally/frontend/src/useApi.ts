import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "./api";

/** Loads a GET endpoint and reloads whenever `deps` change (screens pass the app version). */
export function useApi<T = any>(path: string | null, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(path !== null);

  const load = useCallback(() => {
    if (path === null) return;
    setLoading(true);
    api.get<T>(path)
      .then((d) => { setData(d); setError(null); })
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, ...deps]);

  useEffect(() => { load(); }, [load]);
  return { data, error, loading, reload: load };
}
