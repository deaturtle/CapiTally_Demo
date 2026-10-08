/**
 * Mirrors backend/src/Support/Permissions.php so the screens can hide actions a role cannot use.
 * This is a convenience only: the server enforces every permission.
 */
const MATRIX: Record<string, string[]> = {
  manager: ["products.view", "inventory.view", "inventory.adjust", "barcode.lookup", "purchases.view", "purchases.create",
            "sales.view", "sales.create", "cash.view", "credits.view", "expenses.view", "returns.create", "reports.view", "activity.view", "export.data"],
  staff: ["products.view", "barcode.lookup", "sales.create", "sales.view", "activity.view"],
  inventory: ["products.view", "inventory.view", "inventory.adjust", "barcode.lookup", "purchases.view", "purchases.create"],
};

export const can = (role: string, ability: string): boolean => role === "owner" || (MATRIX[role] ?? []).includes(ability);
