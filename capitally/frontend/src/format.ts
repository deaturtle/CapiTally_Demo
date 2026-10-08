/** Display helpers only. Amounts arrive from the API as exact strings; nothing here is used for accounting. */

export const peso = (value: string | number | null | undefined) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 0, maximumFractionDigits: 2 })
    .format(Number(value ?? 0));

/** 12.500 -> "12.5", 3.000 -> "3" */
export const qty = (value: string | number | null | undefined) => {
  const n = Number(value ?? 0);
  return Number.isInteger(n) ? String(n) : String(parseFloat(n.toFixed(3)));
};

export const initials = (name: string) =>
  name.split(" ").filter(Boolean).map((w) => w[0]).slice(0, 2).join("").toUpperCase() || "CT";

export const todayISO = () => {
  const d = new Date();
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

/** Parses "YYYY-MM-DD HH:MM:SS" (server local time) safely on all browsers. */
const parse = (s: string) => new Date(s.replace(" ", "T"));

export const dayLabel = (s: string) => {
  const d = parse(s);
  const today = new Date();
  const y = new Date(); y.setDate(today.getDate() - 1);
  const same = (a: Date, b: Date) => a.toDateString() === b.toDateString();
  if (same(d, today)) return "Today";
  if (same(d, y)) return "Yesterday";
  return d.toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
};

export const timeLabel = (s: string) => parse(s).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
export const dateLabel = (s: string) => new Date(s.length === 10 ? `${s}T00:00:00` : s.replace(" ", "T"))
  .toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });

export const greeting = () => {
  const h = new Date().getHours();
  return h < 12 ? "Good morning," : h < 18 ? "Good afternoon," : "Good evening,";
};

export const methodLabel: Record<string, string> = { cash: "Cash", gcash: "GCash", maya: "Maya", credit: "Utang (credit)" };
