<?php
declare(strict_types=1);

namespace CapiTally\Support;

/**
 * Role permission matrix (blueprint Part 6). Enforced server-side by the router.
 * The MVP ships Owner only; the other roles are defined so the schema and
 * checks do not need retrofitting.
 */
final class Permissions
{
    private const MATRIX = [
        'owner'     => ['*'],
        'manager'   => ['products.view', 'inventory.view', 'inventory.adjust', 'barcode.lookup',
                        'purchases.view', 'purchases.create', 'sales.view', 'sales.create',
                        'cash.view', 'credits.view', 'expenses.view', 'returns.create',
                        'reports.view', 'activity.view', 'export.data'],
        'staff'     => ['products.view', 'barcode.lookup', 'sales.create', 'sales.view', 'activity.view'],  // own records only, enforced in the routes
        'inventory' => ['products.view', 'inventory.view', 'inventory.adjust', 'barcode.lookup',
                        'purchases.view', 'purchases.create'],
    ];

    public static function can(string $role, string $ability): bool
    {
        $granted = self::MATRIX[$role] ?? [];
        return in_array('*', $granted, true) || in_array($ability, $granted, true);
    }
}
