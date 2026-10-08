import { FormEvent, useEffect, useState } from "react";
import { api, ApiError, fetchAllProducts } from "../api";
import { useApp } from "../context";
import { peso, qty } from "../format";
import { Busy, Loading, Notice, Select } from "../components/ui";

/** Periodic physical count. Differences become reasoned adjustments in the stock ledger. */
export function PeriodicInventoryForm() {
  const app = useApp();
  const [products, setProducts] = useState<any[] | null>(null);
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [reason, setReason] = useState("miscount");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any[] | null>(null);

  useEffect(() => {
    fetchAllProducts().then((list) => {
      setProducts(list);
      setCounts(Object.fromEntries(list.map((p) => [p.id, String(Number(p.quantity_on_hand))])));
    }).catch((e: ApiError) => setError(e.message));
  }, []);

  if (!products) return error ? <Notice>{error}</Notice> : <Loading />;

  const changes = products
    .map((p) => ({ p, diff: Math.round((Number(counts[p.id] ?? p.quantity_on_hand) - Number(p.quantity_on_hand)) * 1000) / 1000 }))
    .filter((c) => c.diff !== 0 && counts[c.p.id] !== "" && Number(counts[c.p.id]) >= 0);
  const units = changes.reduce((s, c) => s + c.diff, 0);
  const value = changes.reduce((s, c) => s + c.diff * Number(c.p.cost_price), 0);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (changes.length === 0) { setError("Nothing is different from your records, so there is nothing to save."); return; }
    if (changes.length > 200) { setError("Please save in smaller batches (200 products with differences at a time)."); return; }
    setBusy(true); setError(null);
    try {
      const res = await api.post("/inventory/adjustments", {
        adjustment_type: "periodic_count", note: "Periodic count",
        items: changes.map((c) => ({ product_id: c.p.id, counted_qty: counts[c.p.id], reason_code: reason })),
      });
      app.refresh(); setResult(res.items);
    } catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };

  if (result) {
    return (
      <div className="space-y-4">
        <Notice tone="info">Count saved. {result.length} {result.length === 1 ? "product was" : "products were"} corrected.</Notice>
        <button type="button" onClick={() => { app.closeModal(); app.go("Inventory"); }} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button>
      </div>
    );
  }

  return (
    <form onSubmit={submit}>
      <div className="rounded-xl bg-[#edf6ff] p-4">
        <p className="text-sm font-bold text-[#315d82]">Count what is on the shelf now</p>
        <p className="mt-1 text-xs leading-5 text-[#577891]">Your records already go down with every sale. Use this to catch anything missing or extra, such as damage, loss or a missed entry.</p>
      </div>
      <div className="mt-5 max-h-[42vh] divide-y divide-[#e8eef3] overflow-y-auto pr-1">
        {products.length === 0 && <p className="py-6 text-center text-sm text-[#7e91a5]">No products yet.</p>}
        {products.map((p) => (
          <div key={p.id} className="flex items-center gap-3 py-3.5">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[#e9f4ff] text-[10px] font-black text-[#0879df]">{p.name.split(" ").map((w: string) => w[0]).slice(0, 2).join("")}</span>
            <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{p.name}</p><p className="text-[11px] text-[#8495a5]">Records show: {qty(p.quantity_on_hand)} {p.unit}</p></div>
            <label className="w-[86px]"><span className="mb-1 block text-[10px] font-bold text-[#718497]">COUNTED</span>
              <input required min="0" step="any" type="number" value={counts[p.id] ?? ""} onChange={(e) => setCounts({ ...counts, [p.id]: e.target.value })} className="w-full rounded-lg border border-[#cedce8] px-3 py-2 text-center text-sm font-bold outline-none focus:border-[#0879e6]" /></label>
          </div>
        ))}
      </div>
      <div className="mt-4 rounded-2xl bg-[#f5f8fb] p-4">
        <div className="flex items-center justify-between"><p className="text-xs font-bold text-[#536a80]">DIFFERENCES FOUND</p><p className="text-[10px] text-[#8495a5]">{changes.length} products</p></div>
        <div className="mt-3 grid grid-cols-2 gap-3">
          <div><p className="text-[10px] text-[#7e91a5]">Units over (+) or short (−)</p><p className="mt-1 font-bold">{units > 0 ? "+" : ""}{qty(units)}</p></div>
          <div><p className="text-[10px] text-[#7e91a5]">Value at cost</p><p className={`mt-1 font-bold ${value < 0 ? "text-[#e77a22]" : "text-[#12966c]"}`}>{peso(value)}</p></div>
        </div>
      </div>
      <div className="mt-4"><Select label="Reason for the differences" value={reason} onChange={(e: any) => setReason(e.target.value)}><option value="miscount">Miscount or missed entry</option><option value="damage">Damaged</option><option value="expired">Expired</option><option value="theft">Lost or stolen</option><option value="other">Other</option></Select></div>
      {error && <div className="mt-3"><Notice>{error}</Notice></div>}
      <Busy busy={busy}>Save count</Busy>
    </form>
  );
}
