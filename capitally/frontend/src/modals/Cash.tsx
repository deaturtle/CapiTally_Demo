import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { peso, todayISO } from "../format";
import { Busy, Input, Notice, Select } from "../components/ui";
import { useApi } from "../useApi";

const Methods = () => <><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option></>;

function useSubmit(action: (d: FormData) => Promise<any>, after?: (res: any) => void) {
  const app = useApp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<any>(null);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault(); setBusy(true); setError(null);
    try { const res = await action(new FormData(e.currentTarget)); app.refresh(); after?.(res); setDone(res); }
    catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };
  return { submit, error, busy, done };
}
const str = (d: FormData, k: string) => String(d.get(k) ?? "").trim();

/** Physical count. The difference from the records is posted as an adjustment. */
export function CashCountForm() {
  const app = useApp();
  const { submit, error, busy, done } = useSubmit((d) => api.post("/cash/counts", { counted_amount: str(d, "counted"), payment_method: str(d, "method"), note: str(d, "note") || undefined }));
  if (done) return <Result title="Cash count saved" text={Number(done.variance) === 0 ? "Your count matches your records." : `Your count was ${peso(Math.abs(Number(done.variance)))} ${Number(done.variance) > 0 ? "more" : "less"} than your records, so the records were corrected.`} onDone={app.closeModal} />;
  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="rounded-xl bg-[#edf6ff] p-4 text-xs leading-5 text-[#416783]">Count the money you actually have. CapiTally compares it with your records and fixes any difference.</div>
      <Input required min="0" step="any" type="number" name="counted" label="Amount counted (₱)" />
      <Select label="What did you count?" name="method" defaultValue="cash"><Methods /></Select>
      <Input name="note" label="Note (optional)" maxLength={255} />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save count</Busy>
    </form>
  );
}

export function WithdrawForm() {
  const app = useApp();
  const { data: c } = useApi<any>("/cash/summary", [app.version]);
  const [amount, setAmount] = useState("");
  const { submit, error, busy, done } = useSubmit((d) => api.post("/cash/withdrawals", { amount: str(d, "amount"), payment_method: str(d, "method"), note: str(d, "note") || undefined }));
  const safe = c ? Number(c.safe_to_withdraw) : null;
  const over = safe !== null && Number(amount) > Math.max(0, safe);

  if (done) return <Result title="Withdrawal recorded" tone={done.exceeded_safe_limit ? "warn" : "info"} text={done.exceeded_safe_limit ? "This withdrawal went into your protected capital. It was saved, but your business now has less money set aside for restocking." : "Your protected capital is untouched."} onDone={app.closeModal} />;
  return (
    <form onSubmit={submit} className="space-y-4">
      {safe !== null && <div className="rounded-xl bg-[#e9f8f3] p-4 text-xs leading-5 text-[#12966c]">Safe to withdraw right now: <b>{peso(Math.max(0, safe))}</b></div>}
      <Input required min="0" step="any" type="number" name="amount" label="Amount (₱)" value={amount} onChange={(e: any) => setAmount(e.target.value)} />
      <Select label="Take it from" name="method" defaultValue="cash"><Methods /></Select>
      <Input name="note" label="Note (optional)" maxLength={255} />
      {over && <Notice tone="warn">This is more than the safe amount. You can still withdraw it, but it will cut into your protected capital.</Notice>}
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Record withdrawal</Busy>
    </form>
  );
}

export function ExpenseForm() {
  const app = useApp();
  const { data: cats } = useApi<any[]>("/expenses/categories", []);
  const { submit, error, busy, done } = useSubmit((d) => api.post("/expenses", { category_name: str(d, "category"), expense_date: str(d, "date"), amount: str(d, "amount"), description: str(d, "description") || undefined, payment_method: str(d, "method") }));
  if (done) return <Result title="Expense saved" text="It was taken off your cash and counted in your total profit." onDone={app.closeModal} />;
  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="rounded-xl bg-[#fff3e8] p-4 text-xs leading-5 text-[#9a5a12]">Rent, electricity, ice, supplies. Expenses lower your total profit but do not change your stock.</div>
      <Input required name="category" list="expense-cats" label="What is it for?" placeholder="e.g. Electricity" maxLength={80} />
      <datalist id="expense-cats">{(cats ?? []).map((c) => <option key={c.id} value={c.name} />)}</datalist>
      <div className="grid grid-cols-2 gap-3"><Input required min="0" step="any" type="number" name="amount" label="Amount (₱)" /><Input required type="date" name="date" label="Date" defaultValue={todayISO()} max={todayISO()} /></div>
      <Input name="description" label="Details (optional)" maxLength={255} />
      <Select label="Paid with" name="method" defaultValue="cash"><Methods /></Select>
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save expense</Busy>
    </form>
  );
}

export function ReserveForm() {
  const app = useApp();
  const { submit, error, busy } = useSubmit((d) => api.put("/cash/reserve", { reserve_cash: str(d, "reserve") }), () => { app.reloadProfile(); app.closeModal(); });
  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="rounded-xl bg-[#edf6ff] p-4 text-xs leading-5 text-[#416783]">Reserve cash is extra money you choose to keep aside, on top of your stock value. Together they make up your protected capital.</div>
      <Input required min="0" step="any" type="number" name="reserve" label="Reserve cash (₱)" defaultValue={String(Number(app.store.reserve_cash))} />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save reserve</Busy>
    </form>
  );
}

function Result({ title, text, onDone, tone = "info" }: { title: string; text: string; onDone: () => void; tone?: "info" | "warn" }) {
  return (
    <div className="space-y-4 text-center">
      <h3 className="text-xl font-bold">{title}</h3>
      <Notice tone={tone}>{text}</Notice>
      <button type="button" onClick={onDone} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button>
    </div>
  );
}
