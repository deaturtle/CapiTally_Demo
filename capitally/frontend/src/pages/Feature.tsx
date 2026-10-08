import { useEffect, useState } from "react";
import { api, ApiError, qs } from "../api";
import { useApp } from "../context";
import { dateLabel, methodLabel, peso, qty } from "../format";
import { Icon } from "../components/Icon";
import { Chip, EmptyCard, Loading, MoneyCard, Notice, PageHeader } from "../components/ui";
import { useApi } from "../useApi";
import { can } from "../permissions";
import { ProductDetail, ProductForm } from "../modals/Product";
import { PeriodicInventoryForm } from "../modals/StockCount";
import { CashCountForm, ExpenseForm, ReserveForm, WithdrawForm } from "../modals/Cash";
import { CreditDetail } from "../modals/Credit";
import { PurchaseDetail, PurchaseForm } from "../modals/Purchase";
import { Receipt, SaleForm } from "../modals/Sale";
import { NoteForm } from "../modals/Misc";

const config: Record<string, [string, string, string]> = {
  Inventory: ["Inventory", "Products, stock and prices", "Add product"],
  "Daily Cash": ["Daily Cash", "Protect your capital and see what is safe to withdraw", "Record cash"],
  Creditors: ["Credits & Creditors", "Track every customer utang", "Add credit"],
  Purchases: ["Purchases", "Record where inventory was purchased", "Add purchase"],
  "Receipt Maker": ["Receipt Maker", "Create simple customer receipts", "New receipt"],
  Notes: ["Notes", "Store reminders and to-do items", "New note"],
};

export function FeaturePage({ page }: { page: string }) {
  const app = useApp();
  const [title, subtitle, action] = config[page] ?? [page, "CapiTally", "Add"];
  const onAdd = () => {
    switch (page) {
      case "Inventory": return app.openModal("Add product", <ProductForm />);
      case "Daily Cash": return app.openModal("Record cash", <CashCountForm />);
      case "Creditors": return app.openModal("Sell on utang", <SaleForm initialMethod="credit" />);
      case "Purchases": return app.openModal("Receive stock", <PurchaseForm />);
      case "Receipt Maker": return app.openModal("New sale", <SaleForm />);
      case "Notes": return app.openModal("New note", <NoteForm />);
    }
  };
  const addAbility: Record<string, string> = { Inventory: "products.manage", "Daily Cash": "cash.manage", Creditors: "sales.create", Purchases: "purchases.create", "Receipt Maker": "sales.create", Notes: "notes.manage" };
  const canAdd = can(app.user.role, addAbility[page] ?? "products.manage");
  return (
    <main className="min-h-screen bg-[#f5f8fb] pb-28">
      <PageHeader title={title} subtitle={subtitle} action={action} onBack={() => app.go("Home")} onAdd={canAdd ? onAdd : undefined} />
      <div className="mx-auto max-w-4xl p-5 sm:p-8">
        {page === "Inventory" && <InventoryList />}
        {page === "Daily Cash" && <CashView />}
        {page === "Creditors" && <CreditList />}
        {page === "Purchases" && <PurchaseList />}
        {page === "Receipt Maker" && <ReceiptList />}
        {page === "Notes" && <NotesList />}
      </div>
    </main>
  );
}

function InventoryList() {
  const app = useApp();
  const [search, setSearch] = useState("");
  const [term, setTerm] = useState("");
  useEffect(() => { const t = setTimeout(() => setTerm(search.trim()), 250); return () => clearTimeout(t); }, [search]);
  const [sort, setSort] = useState("name");
  const [view, setView] = useState("all");     // all | low | out | inactive
  const { data: products, error, loading } = useApi<any[]>(
    `/products${qs({ search: term, limit: 100, sort, stock: view === "low" || view === "out" ? view : undefined, active: view === "inactive" ? "0" : "1" })}`, [app.version]);

  return <>
    <section className="mb-5 overflow-hidden rounded-2xl bg-gradient-to-r from-[#0879e6] to-[#0565c4] p-5 text-white shadow-[0_8px_24px_rgba(8,121,230,.2)]">
      <div className="flex items-start gap-4">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white/15"><Icon name="calendar" size={21} /></span>
        <div className="min-w-0 flex-1">
          <p className="font-bold">Time for your periodic count?</p>
          <p className="mt-1 text-xs leading-5 text-blue-100">Your stock already goes down with every sale. Count what is on your shelves to catch anything missing or extra.</p>
        </div>
      </div>
      <button onClick={() => app.openModal("Update Inventory", <PeriodicInventoryForm />)} disabled={!can(app.user.role, "inventory.adjust")} className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-white py-3 text-sm font-bold text-[#0879e6] shadow-sm disabled:opacity-60">
        <Icon name="history" size={17} /> Update inventory
      </button>
    </section>

    <div className="relative mb-4"><Icon name="search" size={18} className="absolute left-4 top-3.5 text-[#8a9aaa]" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search inventory" aria-label="Search inventory" className="w-full rounded-xl border border-[#dce6ef] bg-white py-3 pl-11 pr-4 text-sm outline-none focus:border-[#0879e6]" /></div>
    <div className="mb-4 flex flex-wrap items-center gap-2">
      {[["all", "All"], ["low", "Running low"], ["out", "Out of stock"], ["inactive", "Deactivated"]].map(([v, l]) => <Chip key={v} active={view === v} onClick={() => setView(v)}>{l}</Chip>)}
      <select aria-label="Sort products" value={sort} onChange={(e) => setSort(e.target.value)} className="ml-auto rounded-full border border-[#d7e2eb] bg-white px-3 py-2 text-xs font-bold text-[#536a80] outline-none">
        <option value="name">Name</option><option value="stock_low">Least stock first</option><option value="stock_high">Most stock first</option>
        <option value="price_low">Price: low to high</option><option value="price_high">Price: high to low</option><option value="newest">Newest</option>
      </select>
    </div>
    {error && <Notice>{error}</Notice>}
    {loading && !products && <Loading />}
    {products && products.length === 0 && <EmptyCard icon="box" title={term || view !== "all" ? "No matching products" : "No products yet"} text={term || view !== "all" ? "Try a different search or filter." : "Add your first product to start tracking stock and sales."} button="Add product" onClick={() => app.openModal("Add product", <ProductForm />)} />}
    {products && products.length > 0 && (
      <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
        {products.map((p) => {
          const low = Number(p.reorder_point) > 0 && Number(p.quantity_on_hand) <= Number(p.reorder_point);
          return (
            <button key={p.id} onClick={() => app.openModal(p.name, <ProductDetail id={p.id} />)} className="flex w-full items-center gap-3 py-4 text-left">
              <span className="grid h-11 w-11 place-items-center rounded-xl bg-[#e9f4ff] text-xs font-black text-[#0879df]">{p.name.split(" ").map((x: string) => x[0]).slice(0, 2).join("")}</span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">{p.name}{Number(p.is_active) === 0 ? " (deactivated)" : ""}</p>
                <p className="text-xs text-[#8495a5]">{p.cost_price != null ? `Cost ${peso(p.cost_price)} · ` : ""}Sell {peso(p.selling_price)}</p>
                {p.supplier_name && <p className="mt-0.5 truncate text-[9px] font-medium text-[#9aa8b5]">Supplier: {p.supplier_name}</p>}
              </div>
              <div className="text-right"><p className={`text-sm font-bold ${low || Number(p.quantity_on_hand) <= 0 ? "text-[#e77a22]" : ""}`}>{qty(p.quantity_on_hand)}</p><p className="text-[10px] text-[#8a9aaa]">{p.unit} remaining</p></div>
            </button>
          );
        })}
      </div>
    )}
  </>;
}

function CashView() {
  const app = useApp();
  const owner = app.user.role === "owner";
  const { data: c, error, loading } = useApi<any>("/cash/summary", [app.version]);
  const ledger = useApi<any[]>("/cash/ledger?limit=15", [app.version]);
  const [msg, setMsg] = useState<string | null>(null);

  if (loading && !c) return <Loading />;
  if (!c) return <Notice>{error ?? "Could not load your cash."}</Notice>;

  const total = Number(c.total_cash), capital = Number(c.protected_capital);
  const covered = total <= 0 ? 0 : Math.max(0, Math.min(100, (capital / total) * 100));
  const voidEntry = async (row: any) => {
    const path = row.withdrawal_id ? `/cash/withdrawals/${row.withdrawal_id}/void` : `/expenses/${row.expense_id}/void`;
    if (!confirm("Void this entry? Your cash will be put back.")) return;
    try { await api.post(path); app.refresh(); setMsg(null); } catch (e) { setMsg((e as ApiError).message); }
  };
  const label: Record<string, string> = { sale: "Sale", credit_payment: "Utang payment", refund: "Refund", expense: "Expense", withdrawal: "Withdrawal", supplier_payment: "Supplier payment", count_adjustment: "Cash count correction" };

  return <>
    <div className="rounded-3xl bg-gradient-to-br from-[#087eea] to-[#005bbb] p-6 text-white shadow-lg">
      <p className="text-xs text-blue-100">Cash on hand (cash, GCash and Maya)</p>
      <p className="mt-2 text-3xl font-bold">{peso(c.total_cash)}</p>
      <div className="mt-6 h-2 overflow-hidden rounded-full bg-white/20"><div className={`h-full ${c.below_capital ? "bg-[#ffb3bd]" : "bg-[#86d4ff]"}`} style={{ width: `${covered}%` }} /></div>
      <p className="mt-2 text-[11px] text-blue-100">{c.below_capital ? "Your cash is below your protected capital." : "Part of your cash is set aside as protected capital."}</p>
    </div>
    <div className="mt-4 grid gap-3 sm:grid-cols-3">
      <MoneyCard label="Protected capital" value={c.protected_capital} color={c.below_capital ? "text-[#df4a5f]" : "text-[#0879e6]"} />
      <MoneyCard label="Safe to withdraw" value={c.safe_to_withdraw} color="text-[#12966c]" />
      <MoneyCard label="Reserve cash" value={c.reserve_cash} color="text-[#735bd4]" />
    </div>
    <div className="mt-3 grid gap-3 sm:grid-cols-3">
      <MoneyCard label="Stock value" value={c.stock_value} color="text-[#0879e6]" />
      <MoneyCard label="Owed to suppliers" value={c.outstanding_payables} color="text-[#e77a22]" />
      <MoneyCard label="Utang owed to you" value={c.credit_outstanding} color="text-[#e77a22]" />
    </div>
    <div className="mt-3 grid grid-cols-3 gap-3 text-center">
      {Object.entries(c.cash_by_method).map(([m, v]) => <div key={m} className="rounded-2xl bg-white p-4 shadow-sm"><p className="text-[11px] text-[#8495a5]">{methodLabel[m]}</p><p className="mt-1 text-sm font-bold">{peso(v as string)}</p></div>)}
    </div>

    {owner && (
      <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[["Withdraw", <WithdrawForm />], ["Add expense", <ExpenseForm />], ["Count cash", <CashCountForm />], ["Set reserve", <ReserveForm />]].map(([t, node]) => (
          <button key={t as string} onClick={() => app.openModal(t as string, node as any)} className="rounded-full border border-[#0879e6] bg-white py-3 text-xs font-bold text-[#0879e6]">{t as string}</button>
        ))}
      </div>
    )}

    <h2 className="mb-3 mt-7 text-[17px] font-bold">Recent cash movements</h2>
    {msg && <div className="mb-3"><Notice>{msg}</Notice></div>}
    <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
      {(ledger.data ?? []).length === 0 && <p className="py-6 text-center text-sm text-[#7e91a5]">No cash movements yet.</p>}
      {(ledger.data ?? []).map((r) => (
        <div key={r.id} className="flex items-center justify-between gap-3 py-3.5">
          <div className="min-w-0"><p className="truncate text-sm font-semibold">{label[r.entry_type] ?? r.entry_type} · {methodLabel[r.payment_method]}</p><p className="text-[11px] text-[#8495a5]">{dateLabel(r.entry_date)}</p></div>
          <div className="flex items-center gap-3">
            {owner && Number(r.amount) < 0 && (r.withdrawal_id || r.expense_id) && <button onClick={() => voidEntry(r)} className="text-xs font-bold text-[#c2455a]">Void</button>}
            <p className={`text-sm font-bold ${Number(r.amount) < 0 ? "text-[#314860]" : "text-[#12966c]"}`}>{Number(r.amount) > 0 ? "+" : ""}{peso(r.amount)}</p>
          </div>
        </div>
      ))}
    </div>
  </>;
}

function CreditList() {
  const app = useApp();
  const [status, setStatus] = useState("open");
  const { data, error, loading } = useApi<any[]>(`/credits${qs({ status, limit: 100 })}`, [app.version]);
  return <>
    <div className="mb-4 flex gap-2"><Chip active={status === "open"} onClick={() => setStatus("open")}>Open</Chip><Chip active={status === "paid"} onClick={() => setStatus("paid")}>Paid</Chip></div>
    {error && <Notice>{error}</Notice>}
    {loading && !data && <Loading />}
    {data && data.length === 0 && <EmptyCard icon="users" title={status === "open" ? "No utang right now" : "Nothing settled yet"} text="Utang appears here when you complete a sale with the Utang payment option." button="Sell on utang" onClick={() => app.openModal("Sell on utang", <SaleForm initialMethod="credit" />)} />}
    {data && data.length > 0 && (
      <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
        {data.map((c) => (
          <button key={c.id} onClick={() => app.openModal(c.customer_name, <CreditDetail id={c.id} />)} className="flex w-full items-center gap-3 py-4 text-left">
            <span className="grid h-11 w-11 place-items-center rounded-full bg-[#fff3e8] text-[#e77a22]"><Icon name="user" size={19} /></span>
            <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{c.customer_name}</p><p className="truncate text-xs text-[#8495a5]">Sale #{c.sale_no}{c.due_date ? ` · Due ${dateLabel(c.due_date)}` : ""}{Number(c.is_overdue) ? " · overdue" : ""}</p></div>
            <p className={`text-sm font-bold ${Number(c.is_overdue) ? "text-[#e77a22]" : ""}`}>{peso(c.balance)}</p>
          </button>
        ))}
      </div>
    )}
  </>;
}

function PurchaseList() {
  const app = useApp();
  const { data, error, loading } = useApi<any[]>("/purchases?limit=50", [app.version]);
  return <>
    {error && <Notice>{error}</Notice>}
    {loading && !data && <Loading />}
    {data && data.length === 0 && <EmptyCard icon="cart" title="No purchases yet" text="Record each delivery so your stock and average cost stay correct." button="Add purchase" onClick={() => app.openModal("Receive stock", <PurchaseForm />)} />}
    {data && data.length > 0 && (
      <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
        {data.map((p) => (
          <button key={p.id} onClick={() => app.openModal("Purchase", <PurchaseDetail id={p.id} />)} className="flex w-full items-center gap-3 py-4 text-left">
            <span className="grid h-11 w-11 place-items-center rounded-full bg-[#f1edff] text-[#735bd4]"><Icon name="cart" size={19} /></span>
            <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{p.supplier_name ?? "Purchase"}</p><p className="truncate text-xs text-[#8495a5]">{dateLabel(p.purchase_date)}{p.status === "cancelled" ? " · Cancelled" : Number(p.outstanding) > 0 ? ` · ${peso(p.outstanding)} still owed` : " · Paid"}</p></div>
            <p className={`text-sm font-bold ${p.status === "cancelled" ? "line-through opacity-50" : ""}`}>{peso(p.total_cost)}</p>
          </button>
        ))}
      </div>
    )}
  </>;
}

function ReceiptList() {
  const app = useApp();
  const { data, error, loading } = useApi<any[]>("/activity?type=sale&limit=30", [app.version]);
  return <>
    {error && <Notice>{error}</Notice>}
    {loading && !data && <Loading />}
    {data && data.length === 0 && <EmptyCard icon="receipt" title="Create a professional receipt" text="Complete a sale and you can print or share its receipt. Your sales will be listed here." button="New sale" onClick={() => app.openModal("New sale", <SaleForm />)} />}
    {data && data.length > 0 && (
      <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
        {data.map((s) => (
          <button key={s.event_id} onClick={() => app.openModal(`Receipt #${s.reference_no}`, <Receipt id={s.event_id} />)} className="flex w-full items-center gap-3 py-4 text-left">
            <span className="grid h-11 w-11 place-items-center rounded-full bg-[#eaf5ff] text-[#2985c5]"><Icon name="receipt" size={19} /></span>
            <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">Sale #{s.reference_no}{s.label ? ` · ${s.label}` : ""}</p><p className="truncate text-xs text-[#8495a5]">{dateLabel(s.event_at)} · {methodLabel[s.payment_method]}{s.status !== "completed" ? ` · ${String(s.status).replace("_", " ")}` : ""}</p></div>
            <p className="text-sm font-bold">{peso(s.amount)}</p>
          </button>
        ))}
      </div>
    )}
  </>;
}

function NotesList() {
  const app = useApp();
  const { data, error, loading } = useApi<any[]>("/notes?limit=100", [app.version]);
  const act = async (id: number, what: "complete" | "reopen" | "archive") => { try { await api.post(`/notes/${id}/${what}`); app.refresh(); } catch { /* shown on reload */ } };
  return <>
    {error && <Notice>{error}</Notice>}
    {loading && !data && <Loading />}
    {data && data.length === 0 && <EmptyCard icon="note" title="No notes yet" text="Jot down reminders like restocking or following up on utang." button="New note" onClick={() => app.openModal("New note", <NoteForm />)} />}
    <div className="grid gap-3 sm:grid-cols-2">
      {(data ?? []).map((note) => (
        <div key={note.id} className="rounded-2xl border-l-4 border-[#ffcc58] bg-white p-5 shadow-sm">
          <div className="flex justify-between gap-2"><h3 className={`font-bold ${note.status === "done" ? "line-through opacity-50" : ""}`}>{note.title}</h3><Icon name="note" size={18} className="text-[#df6574]" /></div>
          {note.details && <p className="mt-2 text-sm leading-6 text-[#718497]">{note.details}</p>}
          {note.due_date && <p className="mt-2 text-xs font-semibold text-[#0879e6]">Remind on {dateLabel(note.due_date)}</p>}
          <div className="mt-3 flex gap-4 text-xs font-bold">
            {note.status === "open" ? <button onClick={() => act(note.id, "complete")} className="text-[#12966c]">Mark done</button> : <button onClick={() => act(note.id, "reopen")} className="text-[#0879e6]">Reopen</button>}
            <button onClick={() => act(note.id, "archive")} className="text-[#8a9aaa]">Archive</button>
          </div>
        </div>
      ))}
    </div>
  </>;
}
