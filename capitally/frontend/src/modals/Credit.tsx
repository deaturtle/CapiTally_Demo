import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { dateLabel, methodLabel, peso, qty } from "../format";
import { Busy, Input, Loading, Notice, Select } from "../components/ui";
import { useApi } from "../useApi";

export function CreditDetail({ id }: { id: number }) {
  const app = useApp();
  const { data: c, error, loading } = useApi<any>(`/credits/${id}`, [app.version]);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (loading && !c) return <Loading />;
  if (!c) return <Notice>{error ?? "Not found."}</Notice>;
  const owner = app.user.role === "owner";
  const balance = Number(c.balance);

  const pay = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try { await api.post(`/credits/${id}/payments`, { amount: amount || String(balance), payment_method: method }); setAmount(""); app.refresh(); }
    catch (x) { setErr((x as ApiError).message); } finally { setBusy(false); }
  };
  const voidPayment = async (pid: number) => {
    if (!confirm("Void this payment? The money will be taken back out of your cash.")) return;
    try { await api.post(`/credit-payments/${pid}/void`); app.refresh(); } catch (x) { setErr((x as ApiError).message); }
  };

  return (
    <div className="space-y-4">
      <div className="rounded-2xl bg-[#f5f8fb] p-4 text-sm">
        <p className="text-lg font-bold">{c.customer_name}</p>
        {c.customer_phone && <p className="text-xs text-[#7e91a5]">{c.customer_phone}</p>}
        <div className="mt-3 flex justify-between"><span className="text-[#7e91a5]">Sale #{c.sale_no}</span><span className="font-semibold">{dateLabel(c.sale_date)}</span></div>
        {c.due_date && <div className="mt-2 flex justify-between"><span className="text-[#7e91a5]">Due</span><span className={`font-semibold ${Number(c.is_overdue) ? "text-[#e77a22]" : ""}`}>{dateLabel(c.due_date)}{Number(c.is_overdue) ? " · overdue" : ""}</span></div>}
      </div>
      <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
        {c.items.map((i: any, n: number) => <div key={n} className="flex justify-between gap-3 px-4 py-3 text-sm"><span className="min-w-0 truncate">{qty(i.quantity)} × {i.product_name}</span><span className="font-semibold">{peso(i.line_total)}</span></div>)}
      </div>
      <div className="grid grid-cols-3 divide-x divide-[#e7edf3] rounded-xl bg-[#f6f9fc] py-3 text-center">
        <div><p className="text-sm font-bold">{peso(c.original_amount)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Utang</p></div>
        <div><p className="text-sm font-bold">{peso(Number(c.amount_paid) + Number(c.returned_offset))}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Paid or returned</p></div>
        <div><p className={`text-sm font-bold ${balance > 0 ? "text-[#e77a22]" : "text-[#12966c]"}`}>{peso(c.balance)}</p><p className="mt-1 text-[10px] text-[#7e91a5]">Balance</p></div>
      </div>
      {c.payments.length > 0 && (
        <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
          {c.payments.map((p: any) => (
            <div key={p.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
              <div><p className={`font-semibold ${Number(p.is_voided) ? "line-through opacity-50" : ""}`}>{peso(p.amount)} · {methodLabel[p.payment_method]}</p><p className="text-[11px] text-[#8495a5]">{dateLabel(p.paid_at)}{Number(p.is_voided) ? " · voided" : ""}</p></div>
              {owner && !Number(p.is_voided) && c.status !== "cancelled" && <button onClick={() => voidPayment(p.id)} className="text-xs font-bold text-[#c2455a]">Void</button>}
            </div>
          ))}
        </div>
      )}
      {c.status === "open" && owner && (
        <form onSubmit={pay} className="space-y-3 rounded-2xl border border-[#e4ebf2] p-4">
          <p className="text-sm font-bold">Record a payment</p>
          <div className="grid grid-cols-2 gap-3">
            <Input inputMode="decimal" value={amount} onChange={(e: any) => setAmount(e.target.value)} label="Amount (₱)" placeholder={String(balance)} />
            <Select label="Paid with" value={method} onChange={(e: any) => setMethod(e.target.value)}><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option></Select>
          </div>
          <p className="text-[11px] text-[#8a9aaa]">Leave the amount empty to settle the full balance.</p>
          {err && <Notice>{err}</Notice>}
          <Busy busy={busy}>Record payment</Busy>
        </form>
      )}
      {c.status !== "open" && <Notice tone="info">This utang is {c.status}.</Notice>}
      {err && c.status !== "open" && <Notice>{err}</Notice>}
    </div>
  );
}
