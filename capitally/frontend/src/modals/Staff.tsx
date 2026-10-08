import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { dateLabel } from "../format";
import { Busy, Input, Loading, Notice, Outline, Select } from "../components/ui";
import { useApi } from "../useApi";

const roleName: Record<string, string> = { owner: "Owner", manager: "Manager", staff: "Cashier", inventory: "Inventory staff" };
const roleHelp: Record<string, string> = {
  manager: "Can sell, receive stock, adjust stock, cancel or return sales and view reports. Cannot change products or cash.",
  staff: "Can only record sales and see their own sales. Cannot see costs, profit or cash.",
  inventory: "Can receive stock and adjust stock. Cannot sell or see sales and cash.",
};

/** Staff accounts. The server checks every permission; this screen only manages who has an account. */
export function StaffAccounts() {
  const app = useApp();
  const { data, error, loading, reload } = useApi<any[]>("/users", [app.version]);
  const [mode, setMode] = useState<{ type: "add" } | { type: "edit" | "password"; user: any } | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true); setMsg(null);
    try { await fn(); setMode(null); reload(); } catch (e) { setMsg((e as ApiError).message); } finally { setBusy(false); }
  };
  const field = (e: FormEvent<HTMLFormElement>) => { const d = new FormData(e.currentTarget); return (k: string) => String(d.get(k) ?? "").trim(); };

  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error ?? "Could not load accounts."}</Notice>;

  if (mode?.type === "add") {
    return (
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const g = field(e); void run(() => api.post("/users", { username: g("username"), full_name: g("full_name"), email: g("email") || undefined, role: g("role"), password: String(new FormData(e.currentTarget).get("password") ?? "") })); }}>
        <Input required name="full_name" label="Full name" maxLength={120} />
        <Input required name="username" label="Username (for logging in)" autoComplete="off" maxLength={60} />
        <Input name="email" type="email" label="Email (optional)" maxLength={160} />
        <Select label="Role" name="role" defaultValue="staff"><option value="staff">Cashier</option><option value="manager">Manager</option><option value="inventory">Inventory staff</option></Select>
        <Input required name="password" type="password" label="Starting password (8 to 72 characters)" autoComplete="new-password" minLength={8} maxLength={72} />
        <p className="text-[11px] leading-4 text-[#8a9aaa]">{roleHelp.staff} Choose a different role to see what it can do below.</p>
        {msg && <Notice>{msg}</Notice>}
        <Busy busy={busy}>Create account</Busy>
        <button type="button" onClick={() => setMode(null)} className="w-full text-center text-xs font-bold text-[#8a9aaa]">Back</button>
      </form>
    );
  }

  if (mode?.type === "edit") {
    const u = mode.user;
    return (
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const g = field(e); void run(() => api.put(`/users/${u.id}`, { full_name: g("full_name"), email: g("email"), role: g("role") })); }}>
        <Input required name="full_name" label="Full name" defaultValue={u.full_name} maxLength={120} />
        <Input name="email" type="email" label="Email" defaultValue={u.email ?? ""} maxLength={160} />
        <Select label="Role" name="role" defaultValue={u.role}><option value="staff">Cashier</option><option value="manager">Manager</option><option value="inventory">Inventory staff</option></Select>
        <p className="text-[11px] leading-4 text-[#8a9aaa]">Manager: {roleHelp.manager}<br />Cashier: {roleHelp.staff}<br />Inventory staff: {roleHelp.inventory}</p>
        {msg && <Notice>{msg}</Notice>}
        <Busy busy={busy}>Save</Busy>
        <button type="button" onClick={() => setMode(null)} className="w-full text-center text-xs font-bold text-[#8a9aaa]">Back</button>
      </form>
    );
  }

  if (mode?.type === "password") {
    const u = mode.user;
    return (
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); const pw = String(new FormData(e.currentTarget).get("new_password") ?? ""); void run(() => api.post(`/users/${u.id}/password`, { new_password: pw })); }}>
        <p className="text-sm">Set a new password for <b>{u.full_name}</b>. Tell them the new password in person.</p>
        <Input required name="new_password" type="password" label="New password (8 to 72 characters)" autoComplete="new-password" minLength={8} maxLength={72} />
        {msg && <Notice>{msg}</Notice>}
        <Busy busy={busy}>Set password</Busy>
        <button type="button" onClick={() => setMode(null)} className="w-full text-center text-xs font-bold text-[#8a9aaa]">Back</button>
      </form>
    );
  }

  return (
    <div className="space-y-3">
      <Outline onClick={() => setMode({ type: "add" })}>Add a staff account</Outline>
      {msg && <Notice>{msg}</Notice>}
      <div className="divide-y divide-[#edf1f5] rounded-2xl border border-[#e4ebf2]">
        {data.map((u) => (
          <div key={u.id} className="px-4 py-3">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0"><p className={`truncate text-sm font-semibold ${Number(u.is_active) ? "" : "line-through opacity-50"}`}>{u.full_name}</p>
                <p className="text-[11px] text-[#8495a5]">@{u.username} · {roleName[u.role]}{Number(u.is_active) ? "" : " · deactivated"}{u.last_login_at ? ` · last login ${dateLabel(u.last_login_at)}` : ""}</p></div>
            </div>
            {u.role !== "owner" && (
              <div className="mt-2 flex gap-4 text-xs font-bold">
                <button onClick={() => setMode({ type: "edit", user: u })} className="text-[#0879e6]">Edit</button>
                <button onClick={() => setMode({ type: "password", user: u })} className="text-[#0879e6]">Reset password</button>
                <button onClick={() => void run(() => api.post(`/users/${u.id}/${Number(u.is_active) ? "deactivate" : "activate"}`))} className={Number(u.is_active) ? "text-[#c2455a]" : "text-[#12966c]"}>{Number(u.is_active) ? "Deactivate" : "Reactivate"}</button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
