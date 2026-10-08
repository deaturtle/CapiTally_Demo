import { FormEvent, useRef, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { dateLabel, methodLabel, peso, qty, todayISO } from "../format";
import { Icon } from "../components/Icon";
import { addToCart, cartTotal } from "../components/cart";
import { Busy, Chip, Input, Loading, Notice, Outline, Select } from "../components/ui";
import { ProductSearch } from "../components/ProductSearch";
import { useApi } from "../useApi";
import { enqueueSale, isOfflineError, newId, reduceCachedStock } from "../offline";

export function SaleForm({ initialMethod = "cash" }: { initialMethod?: string }) {
  const app = useApp();
  const uuid = useRef(newId());                         // lets the server ignore a repeated upload of this sale
  const [method, setMethod] = useState(initialMethod);
  const [customer, setCustomer] = useState("");
  const [phone, setPhone] = useState("");
  const [due, setDue] = useState("");
  const [override, setOverride] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<any>(null);
  const [savedOffline, setSavedOffline] = useState(false);
  const total = cartTotal(app.cart, "price");
  const canOverride = error?.code === "INSUFFICIENT_STOCK" && app.store.allow_negative_override && app.user.role === "owner";

  const setQty = (id: number, v: string) => app.setCart((old) => old.map((l) => (l.product_id === id ? { ...l, qty: v } : l)));
  const remove = (id: number) => app.setCart((old) => old.filter((l) => l.product_id !== id));
  const useNewPrice = () => {
    const d = error?.details;
    if (d) app.setCart((old) => old.map((l) => (l.product_id === d.product_id ? { ...l, price: d.current_price } : l)));
    setError(null);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (app.cart.length === 0) { setError(new ApiError("Add at least one item.", "VALIDATION", 422)); return; }
    const payload = {
      client_uuid: uuid.current, payment_method: method,
      items: app.cart.map((l) => ({ product_id: l.product_id, quantity: l.qty, expected_price: l.price })),
      customer_name: customer.trim() || undefined, customer_phone: phone.trim() || undefined,
      due_date: method === "credit" && due ? due : undefined, allow_negative_stock: override || undefined,
    };
    const keepOnDevice = () => {
      enqueueSale(app.store.id, {
        id: uuid.current, userId: app.user.id, queuedAt: new Date().toISOString(), total: total.toFixed(2),
        summary: app.cart.map((l) => `${qty(l.qty)} × ${l.name}`).join(", "),
        payload: { ...payload, sold_at: new Date().toISOString() }, status: "waiting",
      });
      reduceCachedStock(app.store.id, app.cart.map((l) => ({ product_id: l.product_id, qty: l.qty })));
      app.setCart(() => []);
      setSavedOffline(true);
    };

    if (!navigator.onLine) { keepOnDevice(); return; }
    setBusy(true); setError(null);
    try {
      const sale = await api.post("/sales", payload);
      app.setCart(() => []);
      app.refresh();
      setDone(sale);
    } catch (err) {
      if (isOfflineError(err)) keepOnDevice(); else setError(err as ApiError);
    } finally { setBusy(false); }
  };

  if (savedOffline) {
    return (
      <div className="space-y-4 text-center">
        <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#fff7e9] text-[#e77a22]"><Icon name="alert" size={30} /></span>
        <h3 className="text-xl font-bold">Saved on this device</h3>
        <p className="text-sm leading-6 text-[#718497]">There is no connection right now, so this sale will upload by itself when you are back online. Stock and cash update after it uploads.</p>
        <button type="button" onClick={app.closeModal} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button>
      </div>
    );
  }

  if (done) {
    return (
      <div className="space-y-4 text-center">
        <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#e9f8f3] text-[#12966c]"><Icon name="check" size={30} /></span>
        <h3 className="text-xl font-bold">Sale #{done.sale_no} saved</h3>
        <p className="text-sm text-[#718497]">{peso(done.total_amount)} · {methodLabel[done.payment_method]}{done.payment_method === "credit" ? ". Recorded as utang, not as cash." : ""}</p>
        <Outline onClick={() => app.openModal(`Receipt #${done.sale_no}`, <Receipt id={done.id} />)}>View receipt</Outline>
        <button type="button" onClick={app.closeModal} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <ProductSearch onPick={(p) => app.setCart((old) => addToCart(old, p))} />

      {app.cart.length === 0 ? (
        <p className="rounded-xl bg-[#f5f8fb] p-4 text-center text-xs text-[#7e91a5]">Scan a barcode or search above to add items.</p>
      ) : (
        <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
          {app.cart.map((l) => (
            <div key={l.product_id} className="flex items-center gap-3 px-4 py-3">
              <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{l.name}</p><p className="text-[11px] text-[#8495a5]">{peso(l.price)} each · {qty(l.stock)} {l.unit} in stock</p></div>
              <input aria-label={`Quantity of ${l.name}`} inputMode="decimal" value={l.qty} onChange={(e) => setQty(l.product_id, e.target.value)} className="w-[72px] rounded-lg border border-[#cedce8] px-2 py-2 text-center text-sm font-bold outline-none focus:border-[#0879e6]" />
              <button type="button" onClick={() => remove(l.product_id)} aria-label={`Remove ${l.name}`} className="grid h-8 w-8 place-items-center rounded-full bg-[#eef3f7] text-[#63788d]"><Icon name="close" size={16} /></button>
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        {["cash", "gcash", "maya", "credit"].map((m) => <Chip key={m} active={method === m} onClick={() => setMethod(m)}>{methodLabel[m]}</Chip>)}
      </div>

      {method === "credit" && (
        <div className="space-y-3 rounded-xl bg-[#fff3e8] p-4">
          <Input required value={customer} onChange={(e: any) => setCustomer(e.target.value)} label="Customer name" maxLength={120} />
          <Input value={phone} onChange={(e: any) => setPhone(e.target.value)} label="Phone (helps tell customers apart)" maxLength={30} />
          <Input type="date" min={todayISO()} value={due} onChange={(e: any) => setDue(e.target.value)} label="Due date (optional)" />
          <p className="text-[11px] leading-4 text-[#9a5a12]">Utang is not counted as cash until the customer pays.</p>
        </div>
      )}
      {method !== "credit" && (
        <Input value={customer} onChange={(e: any) => setCustomer(e.target.value)} label="Customer name (optional)" maxLength={120} />
      )}

      <div className="flex items-center justify-between rounded-2xl bg-[#f5f8fb] p-4"><span className="text-xs font-bold text-[#536a80]">TOTAL</span><span className="text-xl font-bold">{peso(total)}</span></div>

      {error && <Notice>{error.message}</Notice>}
      {error?.code === "PRICE_CHANGED" && <Outline onClick={useNewPrice}>Use the new price</Outline>}
      {canOverride && (
        <label className="flex items-start gap-3 rounded-xl bg-[#fff7e9] p-4 text-xs leading-5 text-[#9a5a12]">
          <input type="checkbox" checked={override} onChange={(e) => setOverride(e.target.checked)} className="mt-1" />
          <span>Sell anyway, even though stock will go below zero. This override is recorded in the audit log.</span>
        </label>
      )}
      <Busy busy={busy}>Complete sale</Busy>
    </form>
  );
}

export function SaleDetail({ id }: { id: number }) {
  const app = useApp();
  const { data: s, error, loading } = useApi<any>(`/sales/${id}`, [app.version]);
  if (loading && !s) return <Loading />;
  if (!s) return <Notice>{error ?? "Sale not found."}</Notice>;
  const canReturn = ["owner", "manager"].includes(app.user.role) && ["completed", "partially_returned"].includes(s.status);

  return (
    <div className="space-y-4">
      <div className="rounded-2xl bg-[#f5f8fb] p-4 text-sm">
        <div className="flex justify-between"><span className="text-[#7e91a5]">Date</span><span className="font-semibold">{dateLabel(s.sale_date)}</span></div>
        <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Paid with</span><span className="font-semibold">{methodLabel[s.payment_method]}</span></div>
        {s.customer_name && <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Customer</span><span className="font-semibold">{s.customer_name}</span></div>}
        <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Status</span><span className="font-semibold capitalize">{String(s.status).replace("_", " ")}</span></div>
      </div>
      <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
        {s.items.map((i: any) => (
          <div key={i.id} className="flex items-center justify-between gap-3 px-4 py-3">
            <div className="min-w-0"><p className="truncate text-sm font-semibold">{i.product_name}</p><p className="text-[11px] text-[#8495a5]">{qty(i.quantity)} × {peso(i.unit_price)}{Number(i.returned_quantity) > 0 ? ` · ${qty(i.returned_quantity)} returned` : ""}</p></div>
            <p className="text-sm font-bold">{peso(i.line_total)}</p>
          </div>
        ))}
      </div>
      <div className="flex items-center justify-between rounded-2xl bg-[#f5f8fb] p-4"><span className="text-xs font-bold text-[#536a80]">TOTAL</span><span className="text-xl font-bold">{peso(s.total_amount)}</span></div>
      <Outline onClick={() => app.openModal(`Receipt #${s.sale_no}`, <Receipt id={s.id} />)}>View receipt</Outline>
      {canReturn && <Outline onClick={() => app.openModal(`Return or cancel #${s.sale_no}`, <ReturnForm sale={s} />)}>Return or cancel this sale</Outline>}
    </div>
  );
}

export function ReturnForm({ sale }: { sale: any }) {
  const app = useApp();
  const [type, setType] = useState<"cancellation" | "return">("cancellation");
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [reason, setReason] = useState("");
  const [restock, setRestock] = useState(true);
  const [refundMethod, setRefundMethod] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const items = Object.entries(counts).filter(([, v]) => Number(v) > 0).map(([id, v]) => ({ sale_item_id: Number(id), quantity: v }));
    if (type === "return" && items.length === 0) { setError("Enter the quantity being returned for at least one item."); return; }
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/sales/${sale.id}/returns`, {
        return_type: type, reason: reason.trim(), goods_restocked: restock,
        refund_method: refundMethod || undefined, items: type === "return" ? items : undefined,
      });
      app.refresh();
      setResult(res);
    } catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };

  if (result) {
    return (
      <div className="space-y-3 text-center">
        <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#e9f8f3] text-[#12966c]"><Icon name="check" size={30} /></span>
        <h3 className="text-xl font-bold">{type === "cancellation" ? "Sale cancelled" : "Return recorded"}</h3>
        <p className="text-sm text-[#718497]">Value {peso(result.total_value)}. {Number(result.credit_offset_amount) > 0 ? `Removed ${peso(result.credit_offset_amount)} from the customer's utang. ` : ""}{Number(result.refund_amount) > 0 ? `Refunded ${peso(result.refund_amount)}.` : ""}</p>
        <button type="button" onClick={app.closeModal} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="flex gap-2"><Chip active={type === "cancellation"} onClick={() => setType("cancellation")}>Cancel whole sale</Chip><Chip active={type === "return"} onClick={() => setType("return")}>Return some items</Chip></div>
      {type === "return" && (
        <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
          {sale.items.map((i: any) => { const left = Number(i.quantity) - Number(i.returned_quantity); return (
            <div key={i.id} className="flex items-center gap-3 px-4 py-3">
              <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{i.product_name}</p><p className="text-[11px] text-[#8495a5]">Up to {qty(left)} can be returned</p></div>
              <input aria-label={`Return quantity for ${i.product_name}`} inputMode="decimal" disabled={left <= 0} placeholder="0" value={counts[i.id] ?? ""} onChange={(e) => setCounts({ ...counts, [i.id]: e.target.value })} className="w-[72px] rounded-lg border border-[#cedce8] px-2 py-2 text-center text-sm font-bold outline-none focus:border-[#0879e6] disabled:opacity-40" />
            </div>); })}
        </div>
      )}
      <Input required value={reason} onChange={(e: any) => setReason(e.target.value)} label="Reason" maxLength={500} />
      <label className="flex items-start gap-3 text-xs leading-5 text-[#536a80]"><input type="checkbox" checked={restock} onChange={(e) => setRestock(e.target.checked)} className="mt-1" /><span>The goods are back on the shelf and can be sold again. Untick if they are damaged.</span></label>
      <Select label="Pay the customer back with" value={refundMethod} onChange={(e: any) => setRefundMethod(e.target.value)}>
        <option value="">{sale.payment_method === "credit" ? "Not needed unless they already paid" : "Same as the original payment"}</option>
        <option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option>
      </Select>
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>{type === "cancellation" ? "Cancel sale" : "Record return"}</Busy>
    </form>
  );
}

export function Receipt({ id }: { id: number }) {
  const app = useApp();
  const { data: s, error, loading } = useApi<any>(`/sales/${id}`, []);
  if (loading && !s) return <Loading />;
  if (!s) return <Notice>{error ?? "Sale not found."}</Notice>;

  const text = [`${app.store.name}`, `Receipt #${s.sale_no} · ${dateLabel(s.sale_date)}`, "",
    ...s.items.map((i: any) => `${qty(i.quantity)} x ${i.product_name}  ${peso(i.line_total)}`), "",
    `Total: ${peso(s.total_amount)}`, `Paid with: ${methodLabel[s.payment_method]}`].join("\n");
  const share = async () => {
    if (navigator.share) { try { await navigator.share({ title: `Receipt #${s.sale_no}`, text }); return; } catch { /* cancelled */ } }
    try { await navigator.clipboard.writeText(text); alert("Receipt copied."); } catch { alert("Could not copy the receipt."); }
  };

  return (
    <div className="space-y-4">
      <div id="receipt" className="rounded-2xl border border-dashed border-[#b8cde0] p-5">
        <div className="text-center"><Icon name="receipt" size={28} className="mx-auto text-[#0879e6]" /><p className="mt-2 text-sm font-bold">{app.store.name}</p>{app.store.address && <p className="text-xs text-[#8495a5]">{app.store.address}</p>}{app.store.phone && <p className="text-xs text-[#8495a5]">{app.store.phone}</p>}</div>
        <p className="mt-4 text-xs text-[#7e91a5]">Receipt #{s.sale_no} · {dateLabel(s.sale_date)}{s.customer_name ? ` · ${s.customer_name}` : ""}</p>
        <div className="mt-3 divide-y divide-[#edf1f5]">
          {s.items.map((i: any) => <div key={i.id} className="flex justify-between gap-3 py-2 text-sm"><span>{qty(i.quantity)} × {i.product_name}</span><span className="font-semibold">{peso(i.line_total)}</span></div>)}
        </div>
        <div className="mt-3 flex justify-between border-t border-[#dce6ef] pt-3 text-base font-bold"><span>Total</span><span>{peso(s.total_amount)}</span></div>
        <p className="mt-1 text-xs text-[#7e91a5]">Paid with {methodLabel[s.payment_method]}{s.status !== "completed" ? ` · ${String(s.status).replace("_", " ")}` : ""}</p>
        <p className="mt-4 text-[10px] leading-4 text-[#8a9aaa]">This is a simple record for you and your customer. It is not an official BIR receipt.</p>
      </div>
      <div className="grid grid-cols-2 gap-3"><Outline onClick={() => window.print()}>Print</Outline><Outline onClick={share}>Share</Outline></div>
    </div>
  );
}
