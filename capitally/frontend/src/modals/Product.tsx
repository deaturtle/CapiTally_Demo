import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { dateLabel, peso, qty } from "../format";
import { Busy, Input, Loading, Notice, Outline, Select } from "../components/ui";
import { useApi } from "../useApi";
import { can } from "../permissions";

type Props = { prefill?: Record<string, string>; product?: any };

/** Add (search-before-create) or edit a product. Cost and selling price are always set by the owner. */
export function ProductForm({ prefill = {}, product }: Props) {
  const app = useApp();
  const editing = !!product;
  const [error, setError] = useState<string | null>(null);
  const [similar, setSimilar] = useState<any[] | null>(null);
  const [busy, setBusy] = useState(false);
  const v = (k: string, fallback = "") => String(product?.[k] ?? prefill[k] ?? fallback);

  const submit = async (e: FormEvent<HTMLFormElement>, confirmDuplicate = false) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    const get = (k: string) => String(d.get(k) ?? "").trim();
    const payload: any = {
      name: get("name"), barcode: get("barcode"), supplier_name: get("supplier_name"), category_name: get("category_name"),
      unit: get("unit") || "pcs", cost_price: get("cost_price"), selling_price: get("selling_price"), reorder_point: get("reorder_point") || "0",
      confirm_duplicate: confirmDuplicate || undefined,
    };
    if (!editing) payload.opening_quantity = get("opening_quantity") || "0";
    setBusy(true); setError(null);
    try {
      if (editing) await api.put(`/products/${product.id}`, payload);
      else await api.post("/products", payload);
      app.refresh(); app.closeModal(); app.go("Inventory");
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr.code === "DUPLICATE_WARNING") setSimilar(apiErr.details?.similar ?? []);
      else setError(apiErr.message);
    } finally { setBusy(false); }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <Input required name="name" label="Product name" placeholder="e.g. Sardines 155g" defaultValue={v("name")} maxLength={160} />
      <Input name="barcode" label="Barcode (optional)" inputMode="numeric" defaultValue={v("barcode")} maxLength={64} />
      <Input name="supplier_name" label="Supplier / store purchased from" placeholder="e.g. Puregold or Mang Ben Wholesale" defaultValue={v("supplier_name")} maxLength={120} />
      <div className="grid grid-cols-2 gap-3">
        <Input name="category_name" label="Category (optional)" defaultValue={v("category_name")} maxLength={80} />
        <Input name="unit" label="Unit" placeholder="pcs, pack, kilo" defaultValue={v("unit", "pcs")} maxLength={20} />
      </div>
      <div className="grid grid-cols-3 gap-3">
        {!editing && <Input min="0" step="any" type="number" name="opening_quantity" label="Quantity" placeholder="0" />}
        <Input required min="0" step="any" type="number" name="cost_price" label="Cost (₱)" placeholder="0" defaultValue={editing ? String(Number(product.cost_price)) : ""} />
        <Input required min="0" step="any" type="number" name="selling_price" label="Sell (₱)" placeholder="0" defaultValue={editing ? String(Number(product.selling_price)) : ""} />
        {editing && <Input min="0" step="any" type="number" name="reorder_point" label="Reorder at" defaultValue={String(Number(product.reorder_point))} />}
      </div>
      {!editing && <Input min="0" step="any" type="number" name="reorder_point" label="Warn me when stock is at or below (optional)" placeholder="0" />}
      {editing && <p className="text-[11px] leading-4 text-[#8a9aaa]">Cost updates automatically each time you receive stock. Change it here only to correct a mistake.</p>}

      {similar && (
        <div className="space-y-2 rounded-xl bg-[#fff7e9] p-4 text-xs leading-5 text-[#9a5a12]">
          <p className="font-bold">A similar product already exists:</p>
          {similar.map((s) => <p key={s.id}>• {s.name}{s.barcode ? ` (${s.barcode})` : ""}{Number(s.is_active) === 0 ? " · deactivated" : ""} <span className="opacity-70">matched by {s.match}</span></p>)}
          <p>Please check Inventory first so you do not create a duplicate.</p>
          <button type="submit" onClick={(e) => { e.preventDefault(); void submit({ preventDefault() {}, currentTarget: e.currentTarget.form! } as any, true); }} className="mt-1 rounded-full border border-[#e77a22] px-4 py-2 font-bold text-[#c2620f]">Save anyway</button>
        </div>
      )}
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>{editing ? "Save changes" : "Save product"}</Busy>
    </form>
  );
}

export function ProductDetail({ id }: { id: number }) {
  const app = useApp();
  const { data: p, error, loading } = useApi<any>(`/products/${id}`, [app.version]);
  const [showHistory, setShowHistory] = useState(false);
  const history = useApi<any[]>(showHistory ? `/inventory/movements?product_id=${id}&limit=20` : null, [app.version]);
  const [counted, setCounted] = useState("");
  const [reason, setReason] = useState("miscount");
  const [msg, setMsg] = useState<{ tone: "error" | "info"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  if (loading && !p) return <Loading />;
  if (!p) return <Notice>{error ?? "Product not found."}</Notice>;
  const isOwner = app.user.role === "owner";

  const adjust = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setMsg(null);
    try {
      await api.post("/inventory/adjustments", { adjustment_type: "manual", items: [{ product_id: id, counted_qty: counted, reason_code: reason }] });
      setCounted(""); app.refresh(); setMsg({ tone: "info", text: "Stock updated." });
    } catch (err) { setMsg({ tone: "error", text: (err as ApiError).message }); } finally { setBusy(false); }
  };
  const toggle = async () => {
    try { await api.post(`/products/${id}/${Number(p.is_active) === 1 ? "deactivate" : "activate"}`); app.refresh(); }
    catch (err) { setMsg({ tone: "error", text: (err as ApiError).message }); }
  };

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-3 divide-x divide-[#e7edf3] rounded-xl bg-[#f6f9fc] py-3 text-center">
        <div><p className="text-sm font-bold">{qty(p.quantity_on_hand)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">{p.unit} in stock</p></div>
        {p.cost_price != null ? <div><p className="text-sm font-bold">{peso(p.cost_price)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Average cost</p></div> : <div><p className="text-sm font-bold">{p.unit}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Unit</p></div>}
        <div><p className="text-sm font-bold">{peso(p.selling_price)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Selling price</p></div>
      </div>
      {Number(p.is_active) === 0 && <Notice tone="warn">This product is deactivated. It stays in past records but cannot be sold or received.</Notice>}
      {isOwner && <ProductForm product={p} />}

      {isOwner && (
        <form onSubmit={adjust} className="space-y-3 rounded-2xl border border-[#e4ebf2] p-4">
          <p className="text-sm font-bold">Correct the stock count</p>
          <p className="text-[11px] leading-4 text-[#8a9aaa]">Enter how many are really on the shelf. The difference is recorded with your reason.</p>
          <div className="grid grid-cols-2 gap-3">
            <Input required type="number" min="0" step="any" value={counted} onChange={(e: any) => setCounted(e.target.value)} label={`Counted (${p.unit})`} />
            <Select label="Reason" value={reason} onChange={(e: any) => setReason(e.target.value)}><option value="miscount">Miscount</option><option value="damage">Damaged</option><option value="expired">Expired</option><option value="theft">Lost or stolen</option><option value="other">Other</option></Select>
          </div>
          {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
          <Busy busy={busy}>Update stock</Busy>
        </form>
      )}

      {can(app.user.role, "inventory.view") && <Outline onClick={() => setShowHistory(!showHistory)}>{showHistory ? "Hide stock history" : "Show stock history"}</Outline>}
      {showHistory && (history.loading ? <Loading /> : (
        <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
          {(history.data ?? []).length === 0 && <p className="p-4 text-center text-xs text-[#7e91a5]">No stock movements yet.</p>}
          {(history.data ?? []).map((m) => (
            <div key={m.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
              <div><p className="font-semibold capitalize">{String(m.movement_type).replace("_", " ")}{m.reason_code ? ` · ${m.reason_code}` : ""}</p><p className="text-[11px] text-[#8495a5]">{dateLabel(m.created_at)} · now {qty(m.quantity_after)}</p></div>
              <p className={`font-bold ${Number(m.quantity_change) < 0 ? "text-[#e77a22]" : "text-[#12966c]"}`}>{Number(m.quantity_change) > 0 ? "+" : ""}{qty(m.quantity_change)}</p>
            </div>
          ))}
        </div>
      ))}
      {isOwner && <button type="button" onClick={toggle} className="w-full text-center text-xs font-bold text-[#c2455a]">{Number(p.is_active) === 1 ? "Deactivate this product" : "Reactivate this product"}</button>}
      {!isOwner && msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
    </div>
  );
}
