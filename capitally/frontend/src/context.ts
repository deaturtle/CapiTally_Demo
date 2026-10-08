import { createContext, ReactNode, useContext } from "react";

export type CartLine = { product_id: number; name: string; unit: string; stock: string; price: string; cost: string; qty: string };

export type AppCtx = {
  user: { id: number; role: string; full_name: string; username: string };
  store: { id: number; name: string; owner_name: string; address: string | null; phone: string | null; allow_negative_override: boolean; reserve_cash: string };
  version: number;                                   // bumps after every change so screens reload
  refresh: () => void;
  go: (page: string) => void;
  openModal: (title: string, node: ReactNode) => void;
  closeModal: () => void;
  cart: CartLine[];
  setCart: (fn: (old: CartLine[]) => CartLine[]) => void;
  draft: CartLine[];                                 // stock being received (purchase in progress)
  setDraft: (fn: (old: CartLine[]) => CartLine[]) => void;
  reloadProfile: () => void;
  logout: () => void;
};

export const AppContext = createContext<AppCtx | null>(null);
export const useApp = () => {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error("useApp must be used inside AppContext");
  return ctx;
};
