import { useState } from "react";
import { api, ApiError } from "../api";
import { useApp } from "../context";
import { initials } from "../format";
import { Icon, IconName } from "../components/Icon";
import { Notice, PageHeader } from "../components/ui";
import { AccountForm, PasswordForm, StoreForm } from "../modals/Misc";
import { StaffAccounts } from "../modals/Staff";
import { useApi } from "../useApi";

const Row = ({ icon, label, onClick, tone = "" }: { icon: IconName; label: string; onClick: () => void; tone?: string }) => (
  <button onClick={onClick} className={`flex w-full items-center gap-3 px-4 py-4 text-left text-sm font-semibold ${tone}`}><Icon name={icon} size={19} /><span className="flex-1">{label}</span><Icon name="arrow" size={16} className="text-[#a3b1bf]" /></button>
);

export function Profile() {
  const app = useApp();
  const { data } = useApi<any>("/profile", [app.version]);
  const [error, setError] = useState<string | null>(null);
  const owner = app.user.role === "owner";
  const roleLabel: Record<string, string> = { owner: "Store owner", manager: "Manager", staff: "Cashier", inventory: "Inventory staff" };

  const toggleOverride = async () => {
    setError(null);
    try { await api.put("/settings", { allow_negative_override: !app.store.allow_negative_override }); app.reloadProfile(); }
    catch (e) { setError((e as ApiError).message); }
  };

  return (
    <main className="min-h-screen bg-[#f5f8fb] pb-28">
      <PageHeader title="Profile" subtitle="Store and account settings" onBack={() => app.go("Home")} />
      <div className="mx-auto max-w-xl space-y-4 p-5 sm:p-8">
        <div className="rounded-2xl bg-white p-6 text-center shadow-sm">
          <div className="mx-auto grid h-20 w-20 place-items-center rounded-full bg-[#f9ca65] text-2xl font-black text-[#70501c]">{initials(app.store.name)}</div>
          <h2 className="mt-4 text-xl font-bold">{app.store.name}</h2>
          <p className="text-sm text-[#7e91a5]">{roleLabel[app.user.role] ?? app.user.role} · {app.user.full_name}</p>
          {(app.store.address || app.store.phone) && <p className="mt-2 text-xs text-[#8a9aaa]">{[app.store.address, app.store.phone].filter(Boolean).join(" · ")}</p>}
        </div>

        <div className="divide-y divide-[#edf1f5] overflow-hidden rounded-2xl bg-white shadow-sm">
          {owner && <Row icon="users" label="Staff accounts" onClick={() => app.openModal("Staff accounts", <StaffAccounts />)} />}
          {owner && <Row icon="box" label="Store information" onClick={() => app.openModal("Store information", <StoreForm />)} />}
          <Row icon="user" label="My account" onClick={() => app.openModal("My account", <AccountForm email={data?.user?.email ?? null} />)} />
          <Row icon="check" label="Change password" onClick={() => app.openModal("Change password", <PasswordForm />)} />
        </div>

        {owner && (
          <div className="rounded-2xl bg-white p-5 shadow-sm">
            <div className="flex items-start gap-4">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-bold">Allow selling below zero stock</p>
                <p className="mt-1 text-xs leading-5 text-[#7e91a5]">When on, you can choose to complete a sale even if the records show too little stock, for example when a delivery is not entered yet. Every override is logged. When off, such sales are blocked.</p>
              </div>
              <button role="switch" aria-checked={app.store.allow_negative_override} onClick={toggleOverride} className={`relative h-7 w-12 shrink-0 rounded-full transition ${app.store.allow_negative_override ? "bg-[#0879e6]" : "bg-[#cfdbe6]"}`}>
                <span className={`absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all ${app.store.allow_negative_override ? "left-6" : "left-1"}`} />
              </button>
            </div>
            {error && <div className="mt-3"><Notice>{error}</Notice></div>}
          </div>
        )}

        <div className="overflow-hidden rounded-2xl bg-white shadow-sm"><Row icon="logout" label="Log out" onClick={app.logout} tone="text-[#c2455a]" /></div>
      </div>
    </main>
  );
}
