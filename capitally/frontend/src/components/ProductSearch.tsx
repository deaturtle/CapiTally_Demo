import { useEffect, useState } from "react";
import { api, qs } from "../api";
import { useApp } from "../context";
import { searchCached } from "../offline";
import { peso, qty } from "../format";
import { Icon } from "./Icon";

/** Name/barcode search so products without barcodes are a first-class path. */
export function ProductSearch({ onPick, placeholder = "Search product by name or barcode" }: { onPick: (p: any) => void; placeholder?: string }) {
  const app = useApp();
  const [q, setQ] = useState("");
  const [results, setResults] = useState<any[]>([]);

  useEffect(() => {
    if (q.trim().length < 1) { setResults([]); return; }
    const t = setTimeout(() => {
      api.get<any[]>(`/products${qs({ search: q.trim(), limit: 8 })}`).then(setResults).catch(() => setResults(searchCached(app.store.id, q) as any[]));
    }, 250);
    return () => clearTimeout(t);
  }, [q]);

  return (
    <div>
      <div className="relative">
        <Icon name="search" size={18} className="absolute left-4 top-3.5 text-[#8a9aaa]" />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} className="w-full rounded-xl border border-[#dce6ef] bg-white py-3 pl-11 pr-4 text-sm outline-none focus:border-[#0879e6]" />
      </div>
      {results.length > 0 && (
        <div className="mt-2 divide-y divide-[#edf1f5] rounded-xl border border-[#e4ebf2] bg-white">
          {results.map((p) => (
            <button type="button" key={p.id} onClick={() => { onPick(p); setQ(""); setResults([]); }} className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
              <span className="min-w-0"><span className="block truncate text-sm font-semibold">{p.name}</span><span className="text-[11px] text-[#8495a5]">{qty(p.quantity_on_hand)} {p.unit} in stock</span></span>
              <span className="text-sm font-bold">{peso(p.selling_price)}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
