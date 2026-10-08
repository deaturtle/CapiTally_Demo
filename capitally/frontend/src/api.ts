/** Thin client for the CapiTally PHP API. Money values stay strings; the server does all calculations. */

const BASE: string = (import.meta.env.VITE_API_BASE as string | undefined) ?? "../backend/public/api";

let csrfToken = "";
let onUnauthorized: (() => void) | null = null;

export class ApiError extends Error {
  constructor(message: string, public code: string, public status: number, public details?: any) {
    super(message);
  }
}

export const setCsrf = (token: string) => { csrfToken = token; };
export const hasCsrf = () => csrfToken !== "";
export const setUnauthorizedHandler = (fn: () => void) => { onUnauthorized = fn; };

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      credentials: "same-origin",
      headers: {
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(method !== "GET" && csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError("We could not reach the server. Check your connection and try again.", "NETWORK", 0);
  }

  let json: any = null;
  try { json = await res.json(); } catch { /* non-JSON response */ }

  if (!json || json.ok !== true) {
    const err = json?.error;
    if (res.status === 401 && !path.startsWith("/auth/login") && !path.startsWith("/auth/me")) onUnauthorized?.();
    throw new ApiError(err?.message ?? "Something went wrong. Please try again.", err?.code ?? "SERVER_ERROR", res.status, err?.details);
  }
  return json.data as T;
}

export const api = {
  get: <T = any>(path: string) => request<T>("GET", path),
  post: <T = any>(path: string, body: unknown = {}) => request<T>("POST", path, body),
  put: <T = any>(path: string, body: unknown) => request<T>("PUT", path, body),
};

export const qs = (params: Record<string, string | number | undefined | null>) => {
  const q = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== "") q.set(k, String(v)); });
  const s = q.toString();
  return s ? `?${s}` : "";
};

/** The API caps pages at 100 rows; this walks the pages for screens that need every product. */
export async function fetchAllProducts(): Promise<any[]> {
  const all: any[] = [];
  for (let offset = 0; offset < 2000; offset += 100) {
    const page = await api.get<any[]>(`/products${qs({ limit: 100, offset, active: "1" })}`);
    all.push(...page);
    if (page.length < 100) break;
  }
  return all;
}

/** Downloads a CSV export through the logged-in session and hands it to the browser as a file. */
export async function downloadCsv(path: string, filename: string): Promise<void> {
  let res: Response;
  try {
    res = await fetch(`${BASE}${path}`, { credentials: "same-origin" });
  } catch {
    throw new ApiError("We could not reach the server. Check your connection and try again.", "NETWORK", 0);
  }
  if (!res.ok) {
    let msg = "The export could not be created.";
    try { msg = (await res.json())?.error?.message ?? msg; } catch { /* not JSON */ }
    throw new ApiError(msg, "EXPORT_FAILED", res.status);
  }
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement("a");
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
