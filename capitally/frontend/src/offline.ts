import { useEffect, useState } from "react";
import { api, ApiError, fetchAllProducts, hasCsrf, setCsrf } from "./api";

/**
 * Offline support. Only recording sales is offline-capable (as the blueprint specifies):
 *  - products are cached on this device so scanning and search keep working,
 *  - sales taken offline wait in a queue and are uploaded when the connection returns,
 *  - every sale carries a client id, so an upload that is retried can never double-record.
 * Prices are never trusted from the device: each queued item carries the price the customer was shown,
 * and the server refuses the upload if the price has since changed.
 */

export const newId = (): string =>
  typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => { const r = Math.floor(Math.random() * 16); return (c === "x" ? r : (r & 3) | 8).toString(16); });

export const isOfflineError = (e: unknown) => e instanceof ApiError && (e.code === "NETWORK" || [502, 503, 504].includes(e.status));

// ---------- tiny persistent store ----------
const read = <T,>(key: string, fallback: T): T => { try { const v = localStorage.getItem(key); return v ? (JSON.parse(v) as T) : fallback; } catch { return fallback; } };
const write = (key: string, value: unknown) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage full or blocked */ } };

// ---------- product cache ----------
export type CachedProduct = { id: number; name: string; barcode: string | null; unit: string; quantity_on_hand: string; cost_price: string | null; selling_price: string; is_active: number };
const pKey = (s: number) => `capitally.products.${s}`;

export const cacheProducts = (storeId: number, list: any[]) =>
  write(pKey(storeId), list.map((p): CachedProduct => ({ id: p.id, name: p.name, barcode: p.barcode, unit: p.unit, quantity_on_hand: p.quantity_on_hand, cost_price: p.cost_price ?? null, selling_price: p.selling_price, is_active: p.is_active })));
export const getCachedProducts = (storeId: number) => read<CachedProduct[]>(pKey(storeId), []);
export const clearProductCache = (storeId: number) => { try { localStorage.removeItem(pKey(storeId)); localStorage.removeItem(`capitally.profile`); } catch { /* ignore */ } };

export const findCachedByBarcode = (storeId: number, code: string) => getCachedProducts(storeId).filter((p) => p.is_active && p.barcode === code);
export const searchCached = (storeId: number, q: string, limit = 8) => {
  const needle = q.trim().toLowerCase();
  return getCachedProducts(storeId).filter((p) => p.is_active && (p.name.toLowerCase().includes(needle) || p.barcode === q.trim())).slice(0, limit);
};

/** Keep the cached stock honest while offline so the same stock is not sold twice. */
export const reduceCachedStock = (storeId: number, lines: { product_id: number; qty: string }[]) => {
  const list = getCachedProducts(storeId);
  for (const l of lines) {
    const p = list.find((x) => x.id === l.product_id);
    if (p) p.quantity_on_hand = String(Math.round((Number(p.quantity_on_hand) - Number(l.qty || 0)) * 1000) / 1000);
  }
  write(pKey(storeId), list);
};

export const cacheProfile = (profile: unknown) => write("capitally.profile", profile);
export const getCachedProfile = () => read<any | null>("capitally.profile", null);

// ---------- sales queue ----------
export type QueuedSale = {
  id: string; userId: number; queuedAt: string; summary: string; total: string; payload: any;
  status: "waiting" | "failed"; error?: string; code?: string; details?: any;
};
const qKey = (s: number) => `capitally.queue.${s}`;
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((f) => f());

export const getQueue = (storeId: number) => read<QueuedSale[]>(qKey(storeId), []);
const setQueue = (storeId: number, q: QueuedSale[]) => { write(qKey(storeId), q); emit(); };
export const enqueueSale = (storeId: number, item: QueuedSale) => setQueue(storeId, [...getQueue(storeId), item]);
export const updateQueued = (storeId: number, id: string, patch: Partial<QueuedSale>) =>
  setQueue(storeId, getQueue(storeId).map((q) => (q.id === id ? { ...q, ...patch } : q)));
export const removeQueued = (storeId: number, id: string) => setQueue(storeId, getQueue(storeId).filter((q) => q.id !== id));

export function useQueue(storeId: number): QueuedSale[] {
  const [q, setQ] = useState(() => getQueue(storeId));
  useEffect(() => {
    const f = () => setQ(getQueue(storeId));
    listeners.add(f); f();
    return () => { listeners.delete(f); };
  }, [storeId]);
  return q;
}

export function useOnline(): boolean {
  const [online, setOnline] = useState(typeof navigator === "undefined" ? true : navigator.onLine);
  useEffect(() => {
    const on = () => setOnline(true), off = () => setOnline(false);
    window.addEventListener("online", on); window.addEventListener("offline", off);
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, []);
  return online;
}

// ---------- sync ----------
let syncing = false;

/** Uploads waiting sales one at a time. Sales recorded by another user wait for that user to log in. */
export async function syncQueue(storeId: number, userId: number): Promise<{ synced: number; stopped: boolean; sessionExpired: boolean }> {
  if (syncing) return { synced: 0, stopped: false, sessionExpired: false };
  syncing = true;
  let synced = 0, stopped = false, sessionExpired = false;
  try {
    if (!hasCsrf()) {
      try { setCsrf((await api.get("/auth/me")).csrf_token); }
      catch (e) { return { synced: 0, stopped: true, sessionExpired: e instanceof ApiError && e.status === 401 }; }
    }
    for (const item of getQueue(storeId)) {
      if (item.status !== "waiting" || item.userId !== userId) continue;
      try {
        await api.post("/sales", item.payload);
        removeQueued(storeId, item.id);
        synced++;
      } catch (e) {
        const err = e as ApiError;
        if (err.status === 401) { stopped = sessionExpired = true; break; }
        if (err.code === "NETWORK" || err.status >= 500) { stopped = true; break; }
        updateQueued(storeId, item.id, { status: "failed", error: err.message, code: err.code, details: err.details });
      }
    }
  } finally { syncing = false; }
  return { synced, stopped, sessionExpired };
}

export async function refreshProductCache(storeId: number): Promise<void> {
  try { cacheProducts(storeId, await fetchAllProducts()); } catch { /* keep the previous cache */ }
}
