import { FormEvent, useState } from "react";
import { api, ApiError, setCsrf } from "../api";
import { Busy, Input, Notice } from "../components/ui";

type Props = { needsSetup: boolean; onDone: (user: any) => void };

/** Login and first-run setup, using the same hero gradient and card style as the Home screen. */
export function AuthScreen({ needsSetup, onDone }: Props) {
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const login = async (username: string, password: string) => {
    const res = await api.post("/auth/login", { username, password });
    setCsrf(res.csrf_token);
    onDone(res.user);
  };

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    const get = (k: string) => String(d.get(k) ?? "").trim();
    setBusy(true);
    setError(null);
    try {
      if (needsSetup) {
        await api.post("/auth/setup", {
          store_name: get("store_name"), owner_name: get("owner_name"), address: get("address"), phone: get("phone"),
          username: get("username"), email: get("email"), password: String(d.get("password") ?? ""),
        });
      }
      await login(get("username"), String(d.get("password") ?? ""));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong. Please try again.");
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#f2f5f9] text-[#192b42]">
      <div className="mx-auto min-h-screen max-w-[1180px] bg-white shadow-[0_0_50px_rgba(15,79,144,.06)]">
        <section className="relative overflow-hidden bg-gradient-to-br from-[#087eea] via-[#0875dc] to-[#005bbb] px-5 pb-28 pt-12 text-white sm:px-10 lg:px-14">
          <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border-[50px] border-white/[.06]" />
          <div className="relative flex items-center gap-3">
            <div className="grid h-11 w-11 place-items-center rounded-full border-2 border-white/40 bg-[#f9ca65] text-sm font-black text-[#70501c]">CT</div>
            <p className="text-xl font-bold">CapiTally</p>
          </div>
          <h1 className="relative mt-8 text-[30px] font-bold tracking-tight">{needsSetup ? "Set up your store" : "Welcome back"}</h1>
          <p className="relative mt-1 text-sm text-blue-100">{needsSetup ? "Create the owner account to get started." : "Log in to manage your store."}</p>
        </section>
        <div className="relative z-10 -mt-16 mx-auto max-w-md px-4 pb-10">
          <form onSubmit={submit} className="space-y-4 rounded-2xl bg-white p-6 shadow-[0_8px_30px_rgba(17,74,128,.13)]">
            {needsSetup && <>
              <Input required name="store_name" label="Store name" maxLength={120} />
              <Input required name="owner_name" label="Owner name" maxLength={120} />
              <Input name="address" label="Address (optional)" maxLength={255} />
              <Input name="phone" label="Phone (optional)" maxLength={30} />
              <Input name="email" type="email" label="Email (optional)" maxLength={160} />
            </>}
            <Input required name="username" label="Username" autoComplete="username" maxLength={60} />
            <Input required name="password" type="password" label={needsSetup ? "Password (8 to 72 characters)" : "Password"} autoComplete={needsSetup ? "new-password" : "current-password"} minLength={needsSetup ? 8 : undefined} maxLength={72} />
            {error && <Notice>{error}</Notice>}
            <Busy busy={busy}>{needsSetup ? "Create store and log in" : "Log in"}</Busy>
          </form>
        </div>
      </div>
    </div>
  );
}
