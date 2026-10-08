import { useApp } from "../context";
import { dayLabel, methodLabel, peso, timeLabel } from "../format";
import { IconName } from "../components/Icon";
import { ActivityRow, Loading, Notice, PageHeader } from "../components/ui";
import { useApi } from "../useApi";
import { useState } from "react";
import { qs } from "../api";
import { ProductSearch } from "../components/ProductSearch";
import { can } from "../permissions";
import { Chip } from "../components/ui";
import { SaleDetail } from "../modals/Sale";
import { PurchaseDetail } from "../modals/Purchase";

const GREEN = "bg-[#e9f8f3] text-[#12966c]", ORANGE = "bg-[#fff3e8] text-[#e77a22]", PURPLE = "bg-[#f1edff] text-[#735bd4]", BLUE = "bg-[#e9f4ff] text-[#0879df]", RED = "bg-[#fff0f2] text-[#df6574]";

/** Maps a real activity event to the Figma row look. */
export function activityView(e: any): { icon: IconName; color: string; title: string; value: string; positive: boolean } {
  const amount = e.amount === null ? "" : peso(e.amount);
  const voided = e.status === "voided" ? " (voided)" : "";
  switch (e.event_type) {
    case "sale": {
      const credit = e.payment_method === "credit";
      const state = e.status !== "completed" ? ` (${String(e.status).replace("_", " ")})` : "";
      return { icon: credit ? "users" : "receipt", color: credit ? ORANGE : GREEN, title: `Sale #${e.reference_no}${e.label ? ` · ${e.label}` : ""}${state}`, value: credit ? amount : `+${amount}`, positive: !credit };
    }
    case "purchase": return { icon: "cart", color: PURPLE, title: `Stock purchase${e.label ? ` · ${e.label}` : ""}${e.status === "cancelled" ? " (cancelled)" : ""}`, value: amount, positive: false };
    case "inventory_adjustment": return { icon: "box", color: BLUE, title: e.status === "periodic_count" ? "Inventory count" : "Stock adjustment", value: "", positive: false };
    case "return": return { icon: "history", color: RED, title: `Return · Sale #${e.reference_no}`, value: amount, positive: false };
    case "cancellation": return { icon: "close", color: RED, title: `Cancelled · Sale #${e.reference_no}`, value: amount, positive: false };
    case "credit_payment": return { icon: "users", color: GREEN, title: `Utang payment · ${e.label}${voided}${e.payment_method ? ` (${methodLabel[e.payment_method]})` : ""}`, value: `+${amount}`, positive: true };
    case "expense": return { icon: "wallet", color: ORANGE, title: `Expense · ${e.label}${voided}`, value: amount, positive: false };
    case "withdrawal": return { icon: "wallet", color: PURPLE, title: `Withdrawal${voided}`, value: amount, positive: false };
    case "supplier_payment": return { icon: "cart", color: PURPLE, title: `Paid supplier · ${e.label ?? "supplier"}${voided}`, value: amount, positive: false };
    default: return { icon: "history", color: BLUE, title: String(e.event_type), value: amount, positive: false };
  }
}

const typeOptions: [string, string][] = [["", "Everything"], ["sale", "Sales"], ["purchase", "Purchases"], ["adjustment", "Stock counts"], ["return", "Returns and cancellations"], ["credit_payment", "Utang payments"], ["expense", "Expenses"], ["withdrawal", "Withdrawals"], ["supplier_payment", "Supplier payments"]];

export function Activity() {
  const app = useApp();
  const [showFilters, setShowFilters] = useState(false);
  const [type, setType] = useState("");
  const [method, setMethod] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [product, setProduct] = useState<{ id: number; name: string } | null>(null);
  const active = [type, method, from, to, product].filter(Boolean).length;
  const { data, error, loading } = useApi<any[]>(`/activity${qs({ limit: 100, type, payment_method: method, from, to, product_id: product?.id })}`, [app.version]);
  const staffView = !can(app.user.role, "reports.view");

  const open = (e: any) => {
    if (e.event_type === "sale") app.openModal(`Sale #${e.reference_no}`, <SaleDetail id={e.event_id} />);
    else if (e.event_type === "purchase") app.openModal("Purchase", <PurchaseDetail id={e.event_id} />);
  };

  const groups: Record<string, any[]> = {};
  (data ?? []).forEach((e) => { (groups[dayLabel(e.event_at)] ??= []).push(e); });

  return (
    <main className="min-h-screen bg-[#f5f8fb] pb-28">
      <PageHeader title="Activity" subtitle="Your latest store transactions" onBack={() => app.go("Home")} />
      <div className="mx-auto max-w-3xl p-5 sm:p-8">
        <div className="mb-4">
          <button onClick={() => setShowFilters(!showFilters)} className="rounded-full border border-[#d7e2eb] bg-white px-4 py-2 text-xs font-bold text-[#536a80]">{showFilters ? "Hide filters" : "Filters"}{active > 0 ? ` (${active})` : ""}</button>
          {showFilters && (
            <div className="mt-3 space-y-3 rounded-2xl bg-white p-4 shadow-sm">
              {!staffView && <label className="block"><span className="mb-1.5 block text-xs font-bold text-[#536a80]">Show</span>
                <select value={type} onChange={(e) => setType(e.target.value)} className="w-full rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-4 py-3 text-sm outline-none">{typeOptions.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>}
              <div><span className="mb-1.5 block text-xs font-bold text-[#536a80]">Paid with</span>
                <div className="flex flex-wrap gap-2">{[["", "Any"], ["cash", "Cash"], ["gcash", "GCash"], ["maya", "Maya"], ["credit", "Utang"]].map(([v, l]) => <Chip key={v} active={method === v} onClick={() => setMethod(v)}>{l}</Chip>)}</div></div>
              <div className="grid grid-cols-2 gap-3">
                <label className="block"><span className="mb-1.5 block text-xs font-bold text-[#536a80]">From</span><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-full rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-3 py-3 text-sm outline-none" /></label>
                <label className="block"><span className="mb-1.5 block text-xs font-bold text-[#536a80]">To</span><input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-full rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-3 py-3 text-sm outline-none" /></label>
              </div>
              <div><span className="mb-1.5 block text-xs font-bold text-[#536a80]">Product</span>
                {product ? <button onClick={() => setProduct(null)} className="rounded-full bg-[#e9f4ff] px-4 py-2 text-xs font-bold text-[#0879df]">{product.name} ✕</button>
                  : <ProductSearch placeholder="Search a product" onPick={(p) => setProduct({ id: p.id, name: p.name })} />}</div>
              {active > 0 && <button onClick={() => { setType(""); setMethod(""); setFrom(""); setTo(""); setProduct(null); }} className="text-xs font-bold text-[#c2455a]">Clear filters</button>}
            </div>
          )}
        </div>
        {loading && !data && <Loading />}
        {error && <Notice>{error}</Notice>}
        {data && data.length === 0 && <p className="rounded-2xl bg-white py-10 text-center text-sm text-[#7e91a5]">{active > 0 ? "Nothing matches these filters." : "No activity yet."}</p>}
        {Object.entries(groups).map(([label, rows]) => (
          <div key={label} className="mb-5">
            <p className="mb-3 text-xs font-bold uppercase tracking-wider text-[#7e91a5]">{label}</p>
            <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4">
              {rows.map((e) => { const v = activityView(e); return (
                <button key={`${e.event_type}-${e.event_id}`} onClick={() => open(e)} className="block w-full text-left">
                  <ActivityRow icon={v.icon} color={v.color} title={v.title} detail={timeLabel(e.event_at)} value={v.value} positive={v.positive} />
                </button>
              ); })}
            </div>
          </div>
        ))}
      </div>
    </main>
  );
}
