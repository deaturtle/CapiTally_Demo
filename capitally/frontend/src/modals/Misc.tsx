import { FormEvent, useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { Icon } from "../components/Icon";
import { Busy, Input, Loading, Notice } from "../components/ui";
import { useApi } from "../useApi";
import { qty } from "../format";

export function NoteForm() {
  const app = useApp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    setBusy(true); setError(null);
    try {
      await api.post("/notes", { title: String(d.get("title") ?? "").trim(), details: String(d.get("details") ?? "").trim() || undefined, due_date: String(d.get("due") ?? "") || undefined });
      app.refresh(); app.closeModal(); app.go("Notes");
    } catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };
  return (
    <form onSubmit={submit} className="space-y-4">
      <Input required name="title" label="Note title" maxLength={160} />
      <Input name="details" label="Details" maxLength={1000} />
      <Input type="date" name="due" label="Remind me on (optional)" />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save note</Busy>
    </form>
  );
}

/** Stock alerts, always calculated from current stock. */
export function Notifications() {
  const app = useApp();
  const { data, error, loading } = useApi<any>("/notifications", [app.version]);
  if (loading && !data) return <Loading />;
  if (!data) return <Notice>{error ?? "Could not load alerts."}</Notice>;
  if (data.counts.total === 0) return <p className="py-6 text-center text-sm text-[#7e91a5]">No alerts. Your stock levels look fine.</p>;
  const go = () => { app.closeModal(); app.go("Inventory"); };
  return (
    <div className="space-y-3">
      {data.out_of_stock.length > 0 && (
        <button onClick={go} className="flex w-full gap-3 rounded-xl bg-[#fff0f2] p-4 text-left"><Icon name="alert" className="shrink-0 text-[#df6574]" />
          <div><p className="text-sm font-bold">{data.out_of_stock.length} {data.out_of_stock.length === 1 ? "item is" : "items are"} out of stock</p><p className="mt-1 text-xs text-[#7e91a5]">{data.out_of_stock.slice(0, 4).map((p: any) => p.name).join(", ")}{data.out_of_stock.length > 4 ? "…" : ""}</p></div></button>
      )}
      {data.low_stock.length > 0 && (
        <button onClick={go} className="flex w-full gap-3 rounded-xl bg-[#fff7e9] p-4 text-left"><Icon name="box" className="shrink-0 text-[#e77a22]" />
          <div><p className="text-sm font-bold">{data.low_stock.length} {data.low_stock.length === 1 ? "item is" : "items are"} running low</p><p className="mt-1 text-xs text-[#7e91a5]">{data.low_stock.slice(0, 4).map((p: any) => `${p.name} (${qty(p.quantity_on_hand)} ${p.unit})`).join(", ")}{data.low_stock.length > 4 ? "…" : ""}</p></div></button>
      )}
    </div>
  );
}

export function StoreForm() {
  const app = useApp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget); const g = (k: string) => String(d.get(k) ?? "").trim();
    setBusy(true); setError(null);
    try { await api.put("/profile/store", { name: g("name"), owner_name: g("owner_name"), address: g("address"), phone: g("phone") }); app.reloadProfile(); app.closeModal(); }
    catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };
  return (
    <form onSubmit={submit} className="space-y-4">
      <Input required name="name" label="Store name" defaultValue={app.store.name} maxLength={120} />
      <Input required name="owner_name" label="Owner name" defaultValue={app.store.owner_name} maxLength={120} />
      <Input name="address" label="Address" defaultValue={app.store.address ?? ""} maxLength={255} />
      <Input name="phone" label="Phone" defaultValue={app.store.phone ?? ""} maxLength={30} />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save store information</Busy>
    </form>
  );
}

export function AccountForm({ email }: { email: string | null }) {
  const app = useApp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    setBusy(true); setError(null);
    try { await api.put("/profile/account", { full_name: String(d.get("full_name") ?? "").trim(), email: String(d.get("email") ?? "").trim() }); app.reloadProfile(); app.closeModal(); }
    catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };
  return (
    <form onSubmit={submit} className="space-y-4">
      <Input required name="full_name" label="Your name" defaultValue={app.user.full_name} maxLength={120} />
      <Input name="email" type="email" label="Email" defaultValue={email ?? ""} maxLength={160} />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Save</Busy>
    </form>
  );
}

export function PasswordForm() {
  const app = useApp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    if (d.get("new_password") !== d.get("confirm")) { setError("The new passwords do not match."); return; }
    setBusy(true); setError(null);
    try { await api.post("/profile/password", { current_password: String(d.get("current_password") ?? ""), new_password: String(d.get("new_password") ?? "") }); setDone(true); }
    catch (err) { setError((err as ApiError).message); } finally { setBusy(false); }
  };
  if (done) return <div className="space-y-4 text-center"><Notice tone="info">Your password was changed.</Notice><button type="button" onClick={app.closeModal} className="w-full rounded-full bg-[#0879e6] py-3.5 text-sm font-bold text-white">Done</button></div>;
  return (
    <form onSubmit={submit} className="space-y-4">
      <Input required name="current_password" type="password" label="Current password" autoComplete="current-password" maxLength={72} />
      <Input required name="new_password" type="password" label="New password (8 to 72 characters)" autoComplete="new-password" minLength={8} maxLength={72} />
      <Input required name="confirm" type="password" label="Type the new password again" autoComplete="new-password" minLength={8} maxLength={72} />
      {error && <Notice>{error}</Notice>}
      <Busy busy={busy}>Change password</Busy>
    </form>
  );
}
