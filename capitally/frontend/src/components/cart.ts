import type { CartLine } from "../context";

export const lineFromProduct = (p: any, qtyStr = "1"): CartLine => ({
  product_id: p.id, name: p.name, unit: p.unit, stock: p.quantity_on_hand, price: p.selling_price, cost: p.cost_price ?? "0", qty: qtyStr,
});

/** Repeated scans of the same product increment one line instead of creating a duplicate. */
export const addToCart = (list: CartLine[], p: any): CartLine[] => {
  const i = list.findIndex((l) => l.product_id === p.id);
  if (i === -1) return [...list, lineFromProduct(p)];
  return list.map((l, idx) => (idx === i ? { ...l, qty: String(Math.round((Number(l.qty || 0) + 1) * 1000) / 1000) } : l));
};

/** Display-only total; the server recalculates everything when the sale is saved. */
export const cartTotal = (list: CartLine[], field: "price" | "cost") =>
  list.reduce((sum, l) => sum + Math.round(Number(l.qty || 0) * Number(l[field] || 0) * 100) / 100, 0);
