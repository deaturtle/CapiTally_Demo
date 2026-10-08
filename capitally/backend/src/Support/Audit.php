<?php
declare(strict_types=1);

namespace CapiTally\Support;

use CapiTally\Database;

/** Writes to the append-only audit_log. Call inside the same transaction as the change. */
final class Audit
{
    public static function log(
        ?int $storeId, ?int $userId, string $action, string $entityType, ?int $entityId,
        ?array $old = null, ?array $new = null
    ): void {
        Database::insert(
            'INSERT INTO audit_log (store_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
             VALUES (:store, :user, :action, :etype, :eid, :old, :new, :ip)',
            [
                'store'  => $storeId,
                'user'   => $userId,
                'action' => $action,
                'etype'  => $entityType,
                'eid'    => $entityId,
                'old'    => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
                'new'    => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    }
}
