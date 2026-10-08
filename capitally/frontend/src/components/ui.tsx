import type { ReactNode } from "react";
import { Icon } from "./Icon";
import { peso } from "../format";

export function BottomItem({ item, active, onClick }: any) {
  return <button onClick={onClick} className={`flex flex-col items-center justify-center gap-1 text-[10px] font-semibold transition ${active ? "text-[#0879e6]" : "text-[#7e91a5]"}`}><Icon name={item.icon} size={21} /><span>{item.name}</span></button>;
}

export function Snapshot({ icon, tone, label, value, detail }: any) {
  const colors: any = { blue: "bg-[#e9f4ff] text-[#0879df]", orange: "bg-[#fff3e8] text-[#e77a22]", green: "bg-[#e9f8f3] text-[#12966c]" };
  return <div className="rounded-2xl border border-[#e4ebf2] bg-white p-4"><div className="flex items-center gap-3"><span className={`grid h-10 w-10 place-items-center rounded-xl ${colors[tone]}`}><Icon name={icon} size={19} /></span><div><p className="text-[11px] font-medium text-[#7e91a5]">{label}</p><p className="mt-0.5 text-lg font-bold">{value}</p></div></div><p className="mt-3 text-[11px] text-[#91a0ae]">{detail}</p></div>;
}

export function ActivityRow({ icon, color, title, detail, value, positive }: any) {
  return <div className="flex items-center gap-3 py-4"><span className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${color}`}><Icon name={icon} size={18} /></span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold">{title}</p><p className="mt-0.5 text-[11px] text-[#8a9aaa]">{detail}</p></div><p className={`text-sm font-bold ${positive ? "text-[#12966c]" : "text-[#314860]"}`}>{value}</p></div>;
}

export function PageHeader({ title, subtitle, onBack, onAdd, action = "Add record" }: any) {
  return <header className="sticky top-0 z-20 border-b border-[#dfe8f0] bg-white/95 px-5 pb-4 pt-5 backdrop-blur sm:px-10"><div className="flex items-center justify-between gap-3"><button onClick={onBack} className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#edf4fa] text-[#244360]"><Icon name="back" /></button><div className="min-w-0 flex-1"><h1 className="truncate text-lg font-bold">{title}</h1><p className="truncate text-xs text-[#7e91a5]">{subtitle}</p></div>{onAdd && <button onClick={onAdd} className="flex items-center gap-1 rounded-full bg-[#0879e6] px-4 py-2.5 text-xs font-bold text-white"><Icon name="plus" size={16} /> <span className="hidden sm:inline">{action}</span></button>}</div></header>;
}

export function MoneyCard({ label, value, color }: any) {
  return <div className="rounded-2xl bg-white p-5 shadow-sm"><p className="text-xs text-[#8495a5]">{label}</p><p className={`mt-2 text-xl font-bold ${color}`}>{peso(value)}</p></div>;
}

export function EmptyCard({ icon, title, text, button, onClick }: any) {
  return <div className="rounded-3xl bg-white px-6 py-14 text-center shadow-sm"><span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#e9f4ff] text-[#0879df]"><Icon name={icon} size={30} /></span><h2 className="mt-5 text-xl font-bold">{title}</h2><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-[#718497]">{text}</p><button onClick={onClick} className="mt-6 rounded-full bg-[#0879e6] px-6 py-3 text-sm font-bold text-white">{button}</button></div>;
}

export function Modal({ title, onClose, children }: any) {
  return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#0c2440]/50 backdrop-blur-[2px] sm:items-center sm:p-5" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}><div className="max-h-[92vh] w-full overflow-y-auto rounded-t-[28px] bg-white shadow-2xl sm:max-w-lg sm:rounded-[28px]"><div className="flex items-center justify-between border-b border-[#e4ebf2] px-6 py-5"><h2 className="text-lg font-bold">{title}</h2><button onClick={onClose} className="grid h-9 w-9 place-items-center rounded-full bg-[#eef3f7] text-[#63788d]"><Icon name="close" size={18} /></button></div><div className="p-6">{children}</div></div></div>;
}

export const Input = ({ label, ...props }: any) => <label className="block"><span className="mb-1.5 block text-xs font-bold text-[#536a80]">{label}</span><input {...props} className="w-full rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-4 py-3 text-sm outline-none transition focus:border-[#0879e6] focus:ring-2 focus:ring-[#ddecfa]" /></label>;

export const Submit = ({ children }: any) => <button type="submit" className="mt-2 w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white shadow-[0_5px_12px_rgba(8,121,230,.2)]">{children}</button>;


export const Select = ({ label, children, ...props }: any) => <label className="block"><span className="mb-1.5 block text-xs font-bold text-[#536a80]">{label}</span><select {...props} className="w-full rounded-xl border border-[#d7e2eb] bg-[#fafcfe] px-4 py-3 text-sm outline-none transition focus:border-[#0879e6] focus:ring-2 focus:ring-[#ddecfa]">{children}</select></label>;

export const Notice = ({ tone = "error", children }: { tone?: "error" | "warn" | "info"; children: ReactNode }) => {
  const styles = { error: "bg-[#fff0f2] text-[#b4374a]", warn: "bg-[#fff7e9] text-[#9a5a12]", info: "bg-[#edf6ff] text-[#315d82]" };
  return <div role={tone === "error" ? "alert" : undefined} className={`rounded-xl p-4 text-xs leading-5 ${styles[tone]}`}>{children}</div>;
};

export const Loading = ({ text = "Loading..." }: { text?: string }) => <p className="py-10 text-center text-sm text-[#7e91a5]">{text}</p>;

export const Chip = ({ active, children, onClick }: any) => <button type="button" onClick={onClick} className={`rounded-full border px-4 py-2 text-xs font-bold transition ${active ? "border-[#0879e6] bg-[#0879e6] text-white" : "border-[#d7e2eb] bg-white text-[#536a80]"}`}>{children}</button>;

export const Outline = ({ children, ...props }: any) => <button type="button" {...props} className="w-full rounded-full border border-[#0879e6] py-3 text-sm font-bold text-[#0879e6] disabled:opacity-50">{children}</button>;

export const Busy = ({ busy, children }: { busy: boolean; children: ReactNode }) => <button type="submit" disabled={busy} className="mt-2 w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white shadow-[0_5px_12px_rgba(8,121,230,.2)] disabled:opacity-60">{busy ? "Saving..." : children}</button>;
