import { ReactNode, useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError, setCsrf, setUnauthorizedHandler } from "./api";
import { AppContext, AppCtx, CartLine } from "./context";
import { Icon, IconName } from "./components/Icon";
import { BottomItem, Loading, Modal } from "./components/ui";
import { Activity } from "./pages/Activity";
import { AuthScreen } from "./pages/Auth";
import { FeaturePage } from "./pages/Feature";
import { Home, SimpleHome } from "./pages/Home";
import { Profile } from "./pages/Profile";
import { Reports } from "./pages/Reports";
import { Notifications } from "./modals/Misc";
import { PendingSales } from "./modals/Pending";
import { Scanner } from "./modals/Scanner";
import { cacheProfile, clearProductCache, getCachedProfile, isOfflineError, refreshProductCache, syncQueue, useOnline, useQueue } from "./offline";
import { can } from "./permissions";

type Boot = "loading" | "setup" | "login" | "ready";

export default function App() {
  const [boot, setBoot] = useState<Boot>("loading");
  const [user, setUser] = useState<AppCtx["user"] | null>(null);
  const [store, setStore] = useState<AppCtx["store"] | null>(null);
  const [page, setPage] = useState("Home");
  const [modal, setModal] = useState<{ title: string; node: ReactNode } | null>(null);
  const [version, setVersion] = useState(0);
  const [cart, setCartState] = useState<CartLine[]>([]);
  const [draft, setDraftState] = useState<CartLine[]>([]);
  const [alertCount, setAlertCount] = useState(0);
  const online = useOnline();
  const queue = useQueue(store?.id ?? 0);

  const refresh = useCallback(() => setVersion((v) => v + 1), []);
  const closeModal = useCallback(() => setModal(null), []);
  const openModal = useCallback((title: string, node: ReactNode) => setModal({ title, node }), []);
  const go = useCallback((p: string) => { setPage(p); window.scrollTo({ top: 0 }); }, []);
  const setCart = useCallback((fn: (old: CartLine[]) => CartLine[]) => setCartState(fn), []);
  const setDraft = useCallback((fn: (old: CartLine[]) => CartLine[]) => setDraftState(fn), []);

  const loadProfile = useCallback(async () => {
    const p = await api.get("/profile");
    cacheProfile(p);
    setUser(p.user);
    setStore({ ...p.store });
  }, []);

  const endSession = useCallback(() => {
    setCsrf(""); setUser(null); setStore(null); setModal(null); setCartState([]); setDraftState([]); setPage("Home");
    setBoot("login");
  }, []);

  useEffect(() => { setUnauthorizedHandler(endSession); }, [endSession]);

  // Decide between setup, login and the app. With no connection, a previously logged-in device can still open the app.
  useEffect(() => {
    (async () => {
      try {
        const status = await api.get("/auth/status");
        if (status.needs_setup) { setBoot("setup"); return; }
        try {
          const me = await api.get("/auth/me");
          setCsrf(me.csrf_token);
          await loadProfile();
          setBoot("ready");
        } catch { setBoot("login"); }
      } catch (e) {
        const cached = getCachedProfile();
        if (isOfflineError(e) && cached) { setUser(cached.user); setStore(cached.store); setBoot("ready"); }
        else setBoot("login");
      }
    })();
  }, [loadProfile]);

  // Stock alerts drive the bell dot.
  useEffect(() => {
    if (boot !== "ready" || !user || !can(user.role, "inventory.view") || !online) return;
    api.get("/notifications").then((n) => setAlertCount(n.counts.total)).catch(() => setAlertCount(0));
  }, [boot, user, version, online]);

  // Keep a copy of the products on this device for offline selling.
  useEffect(() => {
    if (boot !== "ready" || !store || !online) return;
    const t = setTimeout(() => { void refreshProductCache(store.id); }, 1200);
    return () => clearTimeout(t);
  }, [boot, store, online, version]);

  // Upload sales saved while offline: at start, when the connection returns, and every minute while any are waiting.
  useEffect(() => {
    if (boot !== "ready" || !store || !user) return;
    const run = () => {
      if (!navigator.onLine) return;
      void syncQueue(store.id, user.id).then((r) => { if (r.synced > 0) refresh(); if (r.sessionExpired) endSession(); });
    };
    run();
    window.addEventListener("online", run);
    const t = setInterval(run, 60000);
    return () => { window.removeEventListener("online", run); clearInterval(t); };
  }, [boot, store, user, refresh, endSession]);

  const logout = useCallback(async () => {
    try { await api.post("/auth/logout"); } catch (e) { if (e instanceof ApiError && e.code === "NETWORK") { /* the session ends on this device either way */ } }
    if (store) clearProductCache(store.id);   // prices and stock are not left behind on the device
    endSession();
  }, [endSession, store]);

  const ctx = useMemo<AppCtx | null>(() => (user && store ? {
    user, store, version, refresh, go, openModal, closeModal, cart, setCart, draft, setDraft,
    reloadProfile: () => { void loadProfile().then(refresh); }, logout,
  } : null), [user, store, version, refresh, go, openModal, closeModal, cart, setCart, draft, setDraft, loadProfile, logout]);

  if (boot === "loading") return <div className="min-h-screen bg-[#f2f5f9]"><Loading text="Starting CapiTally..." /></div>;
  if (boot === "setup" || boot === "login") {
    return <AuthScreen needsSetup={boot === "setup"} onDone={async (u) => { setUser(u); await loadProfile(); setPage("Home"); setBoot("ready"); }} />;
  }
  if (!ctx || !user) return <div className="min-h-screen bg-[#f2f5f9]"><Loading /></div>;

  const role = user.role;
  const hasDashboard = can(role, "reports.view");
  // Bottom bar by role: the same four slots and Scan button as the Figma design.
  const bottomItems: { name: string; icon: IconName }[] = hasDashboard
    ? [{ name: "Home", icon: "home" }, { name: "Activity", icon: "history" }, { name: "Reports", icon: "chart" }, { name: "Profile", icon: "user" }]
    : can(role, "activity.view")
      ? [{ name: "Home", icon: "home" }, { name: "Activity", icon: "history" }, { name: "Inventory", icon: "box" }, { name: "Profile", icon: "user" }]
      : [{ name: "Home", icon: "home" }, { name: "Inventory", icon: "box" }, { name: "Purchases", icon: "cart" }, { name: "Profile", icon: "user" }];
  const canScan = can(role, "sales.create") || can(role, "purchases.create");

  const notify = () => openModal("Notifications", <Notifications />);
  const showPage = () => {
    if (page === "Home") return hasDashboard ? <Home alertCount={alertCount} onNotify={notify} /> : <SimpleHome alertCount={alertCount} onNotify={notify} />;
    if (page === "Activity") return can(role, "activity.view") ? <Activity /> : <SimpleHome alertCount={alertCount} onNotify={notify} />;
    if (page === "Reports") return hasDashboard ? <Reports /> : <SimpleHome alertCount={alertCount} onNotify={notify} />;
    if (page === "Profile") return <Profile />;
    return <FeaturePage page={page} />;
  };

  const waiting = queue.filter((q) => q.status === "waiting").length;
  const failed = queue.filter((q) => q.status === "failed").length;
  const showBanner = !online || waiting + failed > 0;

  return (
    <AppContext.Provider value={ctx}>
      <div className="min-h-screen bg-[#f2f5f9] text-[#192b42]">
        <div className="mx-auto min-h-screen max-w-[1180px] bg-white shadow-[0_0_50px_rgba(15,79,144,.06)]">
          {showBanner && (
            <div className={`sticky top-0 z-30 flex items-center gap-3 px-4 py-2.5 text-xs font-semibold ${failed > 0 ? "bg-[#fff0f2] text-[#b4374a]" : "bg-[#fff7e9] text-[#9a5a12]"}`}>
              <Icon name={online ? "history" : "alert"} size={16} className="shrink-0" />
              <span className="min-w-0 flex-1">
                {!online ? "You are offline. You can still record sales with the products saved on this device." : failed > 0 ? `${failed} ${failed === 1 ? "sale needs" : "sales need"} your attention.` : "Uploading sales saved while you were offline..."}
                {waiting > 0 ? ` ${waiting} waiting to upload.` : ""}
              </span>
              {waiting + failed > 0 && <button onClick={() => openModal("Sales saved on this device", <PendingSales />)} className="shrink-0 rounded-full border border-current px-3 py-1 font-bold">View</button>}
            </div>
          )}

          {showPage()}

          <div className="fixed inset-x-0 bottom-0 z-40 mx-auto max-w-[1180px]">
            <nav className="relative grid h-[74px] grid-cols-5 border-t border-[#dce6f0] bg-white px-2 shadow-[0_-6px_24px_rgba(20,64,110,.08)] sm:px-10">
              {bottomItems.slice(0, 2).map((item) => <BottomItem key={item.name} item={item} active={page === item.name} onClick={() => go(item.name)} />)}
              <div className="relative">
                {canScan && (
                  <button onClick={() => openModal("Scan a product", <Scanner initialMode={can(role, "sales.create") ? "sale" : "receiving"} />)} className="absolute left-1/2 top-[-25px] flex -translate-x-1/2 flex-col items-center gap-1" aria-label="Scan barcode">
                    <span className="grid h-[58px] w-[58px] place-items-center rounded-full border-[5px] border-white bg-[#0879e6] text-white shadow-[0_5px_16px_rgba(0,111,220,.35)] transition hover:scale-105 hover:bg-[#006cd2]">
                      <Icon name="scan" size={27} />
                    </span>
                    <span className="text-[10px] font-bold text-[#0879e6]">Scan</span>
                  </button>
                )}
              </div>
              {bottomItems.slice(2).map((item) => <BottomItem key={item.name} item={item} active={page === item.name} onClick={() => go(item.name)} />)}
            </nav>
          </div>
        </div>

        {modal && <Modal title={modal.title} onClose={closeModal}>{modal.node}</Modal>}
      </div>
    </AppContext.Provider>
  );
}
