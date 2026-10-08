import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { dateLabel, methodLabel, peso, qty, todayISO } from "../format";
import { Icon } from "../components/Icon";
import { lineFromProduct } from "../components/cart";
import { Busy, Input, Loading, Notice, Select } from "../components/ui";
import { ProductSearch } from "../components/ProductSearch";
import { useApi } from "../useApi";

const lineTotal = (l: { qty: string; cost: string }) => Math.round(Number(l.qty || 0) * Number(l.cost || 0) * 100) / 100;

/** Receiving stock. Lines come from receiving-mode scans or the search box. */
export function PurchaseForm() {
  const app = useApp();
  const [supplier, setSupplier] = useState("");
  const [date, setDate] = useState(todayISO());
  const [reference, setReference] = useState("");
  const [paid, setPaid] = useState<string | null>(null);   // null = follow the total until the user edits it
  const [method, setMethod] = useState("cash");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const total = app.draft.reduce((s, l) => s + lineTotal(l), 0);
  const paidValue = paid ?? total.toFixed(2);

  const edit = (id: number, patch: Partial<{ qty: string; cost: string }>) =>
    app.setDraft((old) => old.map((l) => (l.product_id === id ? { ...l, ...patch } : l)));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (app.draft.length === 0) { setError("Add at least one item."); return; }
    setBusy(true); setError(null);
    try {
      await api.post("/purchases", {
        supplier_name: supplier.trim() || undefined, purchase_date: date, reference_no: reference.trim() || undefined,
        items: app.draft.map((l) => ({ product_id: l.product_id, quantity: l.qty, unit_cost: l.cost })),
        amount_paid: paidValue || "0", payment_method: Number(paidValue) > 0 ? method : undefined,
      });
      app.setDraft(() => []);
      app.refresh();
      app.closeModal();
      app.go("Purchases");
    } catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="rounded-xl bg-[#f1edff] p-4 text-xs leading-5 text-[#66539f]">Receiving stock adds to your inventory and updates the average cost of each item.</div>
      <Input value={supplier} onChange={(e: any) => setSupplier(e.target.value)} label="Supplier / purchased from" placeholder="e.g. Puregold, local wholesaler" maxLength={120} />
      <div className="grid grid-cols-2 gap-3">
        <Input required type="date" max={todayISO()} value={date} onChange={(e: any) => setDate(e.target.value)} label="Date" />
        <Input value={reference} onChange={(e: any) => setReference(e.target.value)} label="Reference no. (optional)" maxLength={60} />
      </div>

      <ProductSearch onPick={(p) => app.setDraft((old) => old.some((l) => l.product_id === p.id) ? old : [...old, { ...lineFromProduct(p), cost: p.cost_price }])} />

      {app.draft.length === 0 ? (
        <p className="rounded-xl bg-[#f5f8fb] p-4 text-center text-xs text-[#7e91a5]">Scan items in Receiving mode or search above to add them.</p>
      ) : (
        <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
          {app.draft.map((l) => (
            <div key={l.product_id} className="px-4 py-3">
              <div className="flex items-center justify-between gap-3"><p className="truncate text-sm font-semibold">{l.name}</p>
                <button type="button" onClick={() => app.setDraft((old) => old.filter((x) => x.product_id !== l.product_id))} aria-label={`Remove ${l.name}`} className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[#eef3f7] text-[#63788d]"><Icon name="close" size={16} /></button></div>
              <div className="mt-2 grid grid-cols-3 gap-3">
                <Input label={`Qty (${l.unit})`} inputMode="decimal" value={l.qty} onChange={(e: any) => edit(l.product_id, { qty: e.target.value })} />
                <Input label="Cost each (₱)" inputMode="decimal" value={l.cost} onChange={(e: any) => edit(l.product_id, { cost: e.target.value })} />
                <div><span className="mb-1.5 block text-xs font-bold text-[#536a80]">Line total</span><p className="py-3 text-sm font-bold">{peso(lineTotal(l))}</p></div>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between rounded-2xl bg-[#f5f8fb] p-4"><span className="text-xs font-bold text-[#536a80]">PURCHASE TOTAL</span><span className="text-xl font-bold">{peso(total)}</span></div>
      <div className="grid grid-cols-2 gap-3">
        <Input inputMode="decimal" value={paidValue} onChange={(e: any) => setPaid(e.target.value)} label="Amount paid now (₱)" />
        <Select label="Paid with" value={method} onChange={(e: any) => setMethod(e.target.value)}><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option></Select>
      </div>
      {Number(paidValue) < total && <Notice tone="info">The rest ({peso(total - Number(paidValue || 0))}) will be recorded as owed to the supplier.</Notice>}
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save purchase</Busy>
    </form>
  );
}

export function PurchaseDetail({ id }: { id: number }) {
  const app = useApp();
  const { data: p, error, loading } = useApi<any>(`/purchases/${id}`, [app.version]);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");

  if (loading && !p) return <Loading />;
  if (!p) return <Notice>{error ?? "Purchase not found."}</Notice>;
  const owing = p.status === "cancelled" ? 0 : Number(p.outstanding);

  const cancel = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try { await api.post(`/purchases/${id}/cancel`, { reason: reason.trim() }); setCancelling(false); app.refresh(); }
    catch (x) { setErr((x as ApiError).message); } finally { setBusy(false); }
  };

  const pay = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try { await api.post(`/purchases/${id}/payments`, { amount: amount || String(owing), payment_method: method }); setAmount(""); app.refresh(); }
    catch (x) { setErr((x as ApiError).message); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-4">
      <div className="rounded-2xl bg-[#f5f8fb] p-4 text-sm">
        <div className="flex justify-between"><span className="text-[#7e91a5]">Supplier</span><span className="font-semibold">{p.supplier_name ?? "Not specified"}</span></div>
        <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Date</span><span className="font-semibold">{dateLabel(p.purchase_date)}</span></div>
        {p.reference_no && <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Reference</span><span className="font-semibold">{p.reference_no}</span></div>}
      </div>
      <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
        {p.items.map((i: any, n: number) => <div key={n} className="flex items-center justify-between gap-3 px-4 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold">{i.product_name}</p><p className="text-[11px] text-[#8495a5]">{qty(i.quantity)} × {peso(i.unit_cost)}</p></div><p className="text-sm font-bold">{peso(i.line_total)}</p></div>)}
      </div>
      <div className="grid grid-cols-3 divide-x divide-[#e7edf3] rounded-xl bg-[#f6f9fc] py-3 text-center">
        <div><p className="text-sm font-bold">{peso(p.total_cost)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Total</p></div>
        <div><p className="text-sm font-bold">{peso(p.amount_paid)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Paid</p></div>
        <div><p className={`text-sm font-bold ${owing > 0 ? "text-[#e77a22]" : ""}`}>{peso(p.outstanding)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Still owed</p></div>
      </div>
      {p.status === "cancelled" && <Notice tone="warn">This purchase was cancelled. Its stock was taken back out and any payments were returned to your cash.</Notice>}
      {owing > 0 && p.status === "completed" && app.user.role === "owner" && (
        <form onSubmit={pay} className="space-y-3 rounded-2xl border border-[#e4ebf2] p-4">
          <p className="text-sm font-bold">Pay the supplier</p>
          <div className="grid grid-cols-2 gap-3">
            <Input inputMode="decimal" value={amount} onChange={(e: any) => setAmount(e.target.value)} label="Amount (₱)" placeholder={String(owing)} />
            <Select label="Paid with" value={method} onChange={(e: any) => setMethod(e.target.value)}><option value="cash">{methodLabel.cash}</option><option value="gcash">{methodLabel.gcash}</option><option value="maya">{methodLabel.maya}</option></Select>
          </div>
          {err && <Notice>{err}</Notice>}
          <Busy busy={busy}>Record payment</Busy>
        </form>
      )}
      {p.status === "completed" && app.user.role === "owner" && (
        cancelling ? (
          <form onSubmit={cancel} className="space-y-3 rounded-2xl border border-[#f3c6cc] p-4">
            <p className="text-sm font-bold">Cancel this purchase</p>
            <p className="text-[11px] leading-4 text-[#8a9aaa]">The stock will be taken back out and any payments to the supplier returned to your cash. This only works if the items have not been sold yet.</p>
            <Input required value={reason} onChange={(e: any) => setReason(e.target.value)} label="Reason" maxLength={500} />
            {err && <Notice>{err}</Notice>}
            <Busy busy={busy}>Cancel purchase</Busy>
          </form>
        ) : <button type="button" onClick={() => setCancelling(true)} className="w-full text-center text-xs font-bold text-[#c2455a]">Cancel this purchase</button>
      )}
    </div>
  );
}
