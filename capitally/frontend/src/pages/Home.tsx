import { useApp } from "../context";
import { dayLabel, greeting, initials, peso, timeLabel } from "../format";
import { Icon, IconName } from "../components/Icon";
import { ActivityRow, Loading, Notice, Snapshot } from "../components/ui";
import { useApi } from "../useApi";
import { can } from "../permissions";
import { Scanner } from "../modals/Scanner";
import { activityView } from "./Activity";

const features: { name: string; short: string; icon: IconName; color: string; ability: string }[] = [
  { name: "Inventory", ability: "products.view", short: "Track your stocks", icon: "box", color: "bg-[#e9f4ff] text-[#0879df]" },
  { name: "Daily Cash", ability: "cash.view", short: "Record cash", icon: "wallet", color: "bg-[#e9f8f3] text-[#12966c]" },
  { name: "Creditors", ability: "credits.view", short: "Track utang", icon: "users", color: "bg-[#fff3e8] text-[#e77a22]" },
  { name: "Purchases", ability: "purchases.view", short: "Store expenses", icon: "cart", color: "bg-[#f1edff] text-[#735bd4]" },
  { name: "Receipt Maker", ability: "sales.view", short: "Create receipts", icon: "receipt", color: "bg-[#eaf5ff] text-[#2985c5]" },
  { name: "Notes", ability: "notes.manage", short: "Quick reminders", icon: "note", color: "bg-[#fff0f2] text-[#df6574]" },
];

export function Home({ onNotify, alertCount }: { onNotify: () => void; alertCount: number }) {
  const app = useApp();
  const { data: d, error, loading } = useApi<any>("/dashboard", [app.version]);

  if (loading && !d) return <main className="min-h-screen pb-28"><Loading /></main>;
  if (!d) return <main className="min-h-screen p-5"><Notice>{error ?? "Could not load your dashboard."}</Notice></main>;

  const cash = d.cash;
  const below = cash.below_capital;
  return (
    <main className="min-h-screen pb-28">
      <section className="relative overflow-hidden bg-gradient-to-br from-[#087eea] via-[#0875dc] to-[#005bbb] px-5 pb-24 pt-5 text-white sm:px-10 lg:px-14">
        <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border-[50px] border-white/[.06]" />
        <div className="pointer-events-none absolute -bottom-32 left-[45%] h-64 w-64 rounded-full bg-white/[.05]" />
        <header className="relative flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="grid h-11 w-11 place-items-center rounded-full border-2 border-white/40 bg-[#f9ca65] text-sm font-black text-[#70501c]">{initials(d.store.name)}</div>
            <div><p className="text-xs text-blue-100">{greeting()}</p><p className="font-bold">{d.store.name}</p></div>
          </div>
          <button onClick={onNotify} className="relative grid h-10 w-10 place-items-center rounded-full bg-white/15 backdrop-blur" aria-label="Notifications">
            <Icon name="bell" size={20} />{alertCount > 0 && <span className="absolute right-2 top-2 h-2 w-2 rounded-full border border-[#0875dc] bg-[#ffce52]" />}
          </button>
        </header>
        <div className="relative mt-8">
          <p className="text-xs font-medium text-blue-100">Available business cash</p>
          <div className="mt-1 flex items-center gap-2"><h1 className="text-[34px] font-bold tracking-tight">{peso(cash.total_cash)}</h1><span className="rounded-full bg-white/15 px-2 py-1 text-[10px] font-semibold">Updated today</span></div>
          <div className="mt-5 flex max-w-md gap-6 border-t border-white/15 pt-4 text-xs">
            <div><p className="text-blue-100">Protected capital</p><p className={`mt-1 font-bold ${below ? "text-[#ffb3bd]" : ""}`}>{peso(cash.protected_capital)}</p></div>
            <div className="border-l border-white/20 pl-6"><p className="text-blue-100">Safe to withdraw</p><p className="mt-1 font-bold text-[#ffe18d]">{peso(cash.safe_to_withdraw)}</p></div>
          </div>
        </div>
      </section>

      <div className="relative z-10 -mt-16 px-4 sm:px-8 lg:px-12">
        <section className="rounded-2xl bg-white p-4 shadow-[0_8px_30px_rgba(17,74,128,.13)] sm:p-6">
          <div className="grid grid-cols-3 gap-y-6 sm:grid-cols-6">
            {features.filter((f) => can(app.user.role, f.ability)).map((feature) => (
              <button key={feature.name} onClick={() => app.go(feature.name)} className="group flex min-w-0 flex-col items-center gap-2 text-center">
                <span className={`grid h-[50px] w-[50px] place-items-center rounded-2xl transition group-hover:-translate-y-0.5 group-hover:shadow-md ${feature.color}`}><Icon name={feature.icon} size={23} /></span>
                <span className="text-[11px] font-bold leading-4 text-[#344a61]">{feature.name}</span>
              </button>
            ))}
          </div>
        </section>

        <section className="mt-6">
          <div className="mb-3 flex items-center justify-between"><h2 className="text-[17px] font-bold">Business snapshot</h2><button onClick={() => app.go("Reports")} className="text-xs font-bold text-[#0879e6]">View report</button></div>
          <div className="grid gap-3 sm:grid-cols-3">
            <Snapshot icon="box" tone="blue" label="Stock value" value={peso(d.stock.stock_value)} detail={`${d.stock.active_products} product types`} />
            <Snapshot icon="users" tone="orange" label="Unpaid credits (utang)" value={peso(d.credits.total_balance)} detail={`${d.credits.open_count} open`} />
            <Snapshot icon="chart" tone="green" label="Possible earnings" value={peso(d.stock.projected_gross_profit)} detail="Projection from current stock, not yet earned" />
          </div>
          <div className="mt-3 grid gap-3 sm:grid-cols-3">
            <Snapshot icon="receipt" tone="blue" label="Sales today" value={peso(d.today.revenue)} detail={`${d.today.sales_count} sales`} />
            <Snapshot icon="chart" tone="green" label="Profit before expenses (today)" value={peso(d.today.gross_profit)} detail={`Total profit ${peso(d.today.net_profit)}`} />
            <Snapshot icon="cart" tone="orange" label="Purchases this month" value={peso(d.purchases_month_to_date)} detail="Stock bought" />
          </div>
        </section>

        <section className={`mt-6 overflow-hidden rounded-2xl p-5 text-white ${below ? "bg-gradient-to-r from-[#7a2433] to-[#a33a49]" : "bg-gradient-to-r from-[#122f59] to-[#174f7f]"}`}>
          <div className="flex items-center gap-4">
            <span className={`grid h-12 w-12 shrink-0 place-items-center rounded-full bg-white/10 ${below ? "text-[#ffc2ca]" : "text-[#69c5ff]"}`}><Icon name={below ? "alert" : "check"} size={25} /></span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold">{below ? "Your cash is below your protected capital" : "Your business cash is healthy"}</p>
              <p className="mt-1 text-xs leading-5 text-blue-100">{below ? "Cash on hand does not cover your stock value, reserve and what you owe suppliers. Avoid withdrawing for now." : `You can withdraw ${peso(cash.safe_to_withdraw)} without touching your inventory capital.`}</p>
            </div>
            <Icon name="arrow" size={18} className="shrink-0 text-blue-200" />
          </div>
        </section>

        <section className="mt-6">
          <div className="mb-3 flex items-center justify-between"><h2 className="text-[17px] font-bold">Recent activity</h2><button onClick={() => app.go("Activity")} className="text-xs font-bold text-[#0879e6]">See all</button></div>
          <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2] bg-white px-4">
            {d.recent_activity.length === 0 && <p className="py-6 text-center text-sm text-[#7e91a5]">Nothing recorded yet. Your sales, purchases and expenses will show here.</p>}
            {d.recent_activity.map((e: any) => { const v = activityView(e); return <ActivityRow key={`${e.event_type}-${e.event_id}`} icon={v.icon} color={v.color} title={v.title} detail={`${dayLabel(e.event_at)}, ${timeLabel(e.event_at)}`} value={v.value} positive={v.positive} />; })}
          </div>
        </section>
      </div>
    </main>
  );
}

/** Home for roles without the business dashboard (cashiers and inventory staff): only what they can do. */
export function SimpleHome({ onNotify, alertCount }: { onNotify: () => void; alertCount: number }) {
  const app = useApp();
  const role = app.user.role;
  const tiles = [
    { name: "New sale", short: "Scan or search", icon: "scan", color: "bg-[#e9f8f3] text-[#12966c]", run: () => app.openModal("Scan a product", <Scanner initialMode="sale" />), show: can(role, "sales.create") },
    { name: "Receive stock", short: "Add a delivery", icon: "cart", color: "bg-[#f1edff] text-[#735bd4]", run: () => app.openModal("Scan a product", <Scanner initialMode="receiving" />), show: can(role, "purchases.create") },
    ...features.filter((f) => can(role, f.ability)).map((f) => ({ name: f.name, short: f.short, icon: f.icon, color: f.color, run: () => app.go(f.name), show: true })),
  ] as { name: string; short: string; icon: IconName; color: string; run: () => void; show: boolean }[];
  const visibleTiles = tiles.filter((t) => t.show);

  return (
    <main className="min-h-screen pb-28">
      <section className="relative overflow-hidden bg-gradient-to-br from-[#087eea] via-[#0875dc] to-[#005bbb] px-5 pb-20 pt-5 text-white sm:px-10 lg:px-14">
        <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border-[50px] border-white/[.06]" />
        <header className="relative flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="grid h-11 w-11 place-items-center rounded-full border-2 border-white/40 bg-[#f9ca65] text-sm font-black text-[#70501c]">{initials(app.store.name)}</div>
            <div><p className="text-xs text-blue-100">{greeting()}</p><p className="font-bold">{app.user.full_name}</p></div>
          </div>
          {can(role, "inventory.view") && (
            <button onClick={onNotify} className="relative grid h-10 w-10 place-items-center rounded-full bg-white/15 backdrop-blur" aria-label="Notifications">
              <Icon name="bell" size={20} />{alertCount > 0 && <span className="absolute right-2 top-2 h-2 w-2 rounded-full border border-[#0875dc] bg-[#ffce52]" />}
            </button>
          )}
        </header>
        <p className="relative mt-6 text-sm text-blue-100">{app.store.name}</p>
      </section>
      <div className="relative z-10 -mt-12 px-4 sm:px-8 lg:px-12">
        <section className="rounded-2xl bg-white p-4 shadow-[0_8px_30px_rgba(17,74,128,.13)] sm:p-6">
          <div className="grid grid-cols-3 gap-y-6 sm:grid-cols-6">
            {visibleTiles.map((t) => (
              <button key={t.name} onClick={t.run} className="group flex min-w-0 flex-col items-center gap-2 text-center">
                <span className={`grid h-[50px] w-[50px] place-items-center rounded-2xl transition group-hover:-translate-y-0.5 group-hover:shadow-md ${t.color}`}><Icon name={t.icon} size={23} /></span>
                <span className="text-[11px] font-bold leading-4 text-[#344a61]">{t.name}</span>
              </button>
            ))}
          </div>
        </section>
      </div>
    </main>
  );
}
