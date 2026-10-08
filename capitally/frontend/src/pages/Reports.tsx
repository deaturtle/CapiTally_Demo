import { useState } from "react";
import { useApp } from "../context";
import { dateLabel, peso, todayISO } from "../format";
import { ActivityRow, Chip, Loading, MoneyCard, Notice, PageHeader } from "../components/ui";
import { useApi } from "../useApi";
import { ApiError, downloadCsv, qs } from "../api";
import { can } from "../permissions";

const iso = (d: Date) => { const p = (n: number) => String(n).padStart(2, "0"); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
const periods: Record<string, () => [string, string]> = {
  Today: () => [todayISO(), todayISO()],
  "This week": () => { const d = new Date(); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return [iso(d), todayISO()]; },
  "This month": () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), todayISO()]; },
};

/** Plain-language labels: cost of items sold, profit before expenses, total profit. */
export function Reports() {
  const app = useApp();
  const [period, setPeriod] = useState("Today");
  const [from, to] = periods[period]();
  const range = `from=${from}&to=${to}`;
  const sum = useApi<any>(`/reports/summary?${range}`, [app.version, period]);
  const series = useApi<any[]>(`/reports/sales?${range}&granularity=day`, [app.version, period]);
  const cash = useApi<any>("/cash/summary", [app.version]);
  const credits = useApi<any>("/reports/credits", [app.version]);
  const owner = app.user.role === "owner";
  const [exportError, setExportError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<string | null>(null);
  const exportFile = async (type: string, ranged: boolean) => {
    setExporting(type); setExportError(null);
    try { await downloadCsv(`/export/${type}${ranged ? qs({ from, to }) : ""}`, `capitally-${type}.csv`); }
    catch (e) { setExportError((e as ApiError).message); } finally { setExporting(null); }
  };

  const s = sum.data;
  return (
    <main className="min-h-screen bg-[#f5f8fb] pb-28">
      <PageHeader title="Reports" subtitle="Your business at a glance" onBack={() => app.go("Home")} />
      <div className="mx-auto max-w-4xl p-5 sm:p-8">
        <div className="mb-4 flex gap-2">{Object.keys(periods).map((p) => <Chip key={p} active={period === p} onClick={() => setPeriod(p)}>{p}</Chip>)}</div>
        {sum.error && <Notice>{sum.error}</Notice>}
        {sum.loading && !s && <Loading />}
        {s && (
          <>
            <div className="grid gap-4 sm:grid-cols-2">
              <MoneyCard label={`Sales${Number(s.returns_value) > 0 ? " (after returns)" : ""}`} value={s.revenue} color="text-[#0879e6]" />
              <MoneyCard label="Cost of items sold" value={s.cost_of_sales} color="text-[#735bd4]" />
              <MoneyCard label="Profit before expenses" value={s.gross_profit} color="text-[#12966c]" />
              <MoneyCard label="Expenses" value={s.expenses} color="text-[#e77a22]" />
            </div>
            <div className="mt-4"><MoneyCard label="Total profit" value={s.net_profit} color={Number(s.net_profit) < 0 ? "text-[#df4a5f]" : "text-[#12966c]"} /></div>
            <p className="mt-2 text-[11px] leading-4 text-[#8a9aaa]">{s.sales_count} sales · {dateLabel(s.from)}{s.from !== s.to ? ` to ${dateLabel(s.to)}` : ""}. Profit is counted when items are sold, using the cost at that time. Utang counts as sales when given, but not as cash until paid.</p>
          </>
        )}

        <h2 className="mb-3 mt-7 text-[17px] font-bold">Where your money stands</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          {cash.data && <>
            <MoneyCard label="Cash on hand" value={cash.data.total_cash} color="text-[#0879e6]" />
            <MoneyCard label="Stock value" value={cash.data.stock_value} color="text-[#735bd4]" />
            <MoneyCard label="Safe to withdraw" value={cash.data.safe_to_withdraw} color="text-[#12966c]" />
          </>}
          {credits.data && <MoneyCard label="Outstanding credits (utang)" value={credits.data.total_balance} color="text-[#e77a22]" />}
        </div>
        {credits.data && Number(credits.data.overdue_count) > 0 && <div className="mt-3"><Notice tone="warn">{credits.data.overdue_count} utang {Number(credits.data.overdue_count) === 1 ? "is" : "are"} overdue ({peso(credits.data.overdue_amount)}).</Notice></div>}

        {owner && series.data && series.data.length > 0 && (
          <>
            <h2 className="mb-3 mt-7 text-[17px] font-bold">Daily sales</h2>
            <div className="divide-y divide-[#edf1f5] rounded-2xl bg-white px-4 shadow-sm">
              {series.data.map((d) => <ActivityRow key={d.period_start} icon="chart" color="bg-[#e9f4ff] text-[#0879df]" title={dateLabel(d.period_start)} detail={`${d.sales_count} sales${Number(d.returns_value) > 0 ? ` · ${peso(d.returns_value)} returned` : ""}`} value={peso(d.revenue)} />)}
            </div>
          </>
        )}

        {can(app.user.role, "export.data") && (
          <>
            <h2 className="mb-1 mt-7 text-[17px] font-bold">Export to Excel (CSV)</h2>
            <p className="mb-3 text-xs text-[#8a9aaa]">Records with dates use the period chosen above ({dateLabel(from)}{from !== to ? ` to ${dateLabel(to)}` : ""}).</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {([["sales", "Sales", true], ["purchases", "Purchases", true], ["expenses", "Expenses", true], ["cash-ledger", "Cash movements", true], ["inventory-movements", "Stock movements", true], ["products", "Products and stock", false], ["credits", "Utang", false]] as [string, string, boolean][]).map(([t, l, ranged]) => (
                <button key={t} disabled={exporting !== null} onClick={() => exportFile(t, ranged)} className="rounded-full border border-[#0879e6] bg-white py-3 text-xs font-bold text-[#0879e6] disabled:opacity-50">{exporting === t ? "Preparing..." : l}</button>
              ))}
            </div>
            {exportError && <div className="mt-3"><Notice>{exportError}</Notice></div>}
          </>
        )}
      </div>
    </main>
  );
}
