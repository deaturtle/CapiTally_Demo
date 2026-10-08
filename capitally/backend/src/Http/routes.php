<?php
declare(strict_types=1);

use CapiTally\Http\Request;
use CapiTally\Http\Router;
use CapiTally\Services\ActivityService;
use CapiTally\Services\AuthService;
use CapiTally\Services\BarcodeService;
use CapiTally\Services\CashService;
use CapiTally\Services\CreditService;
use CapiTally\Services\ExpenseService;
use CapiTally\Services\ExportService;
use CapiTally\Services\InventoryService;
use CapiTally\Services\NotesService;
use CapiTally\Services\NotificationService;
use CapiTally\Services\ProfileService;
use CapiTally\Services\ProductService;
use CapiTally\Services\PurchaseService;
use CapiTally\Services\ReportService;
use CapiTally\Services\ReturnService;
use CapiTally\Services\SaleService;
use CapiTally\Services\UserService;
use CapiTally\Support\Validator;

$router = new Router();

$intParam = static fn (array $p, string $k): int => Validator::id($p, $k, 'Id');
$limit    = static fn (Request $r): int => (int) ($r->query('limit', '50'));
$offset   = static fn (Request $r): int => (int) ($r->query('offset', '0'));
$ownOnly  = static fn (Request $r): ?int => $r->user['role'] === 'staff' ? $r->userId() : null;   // cashiers only see their own records
$hideCost = static function (Request $r, mixed $data): mixed {
    if ($r->user['role'] !== 'staff') {
        return $data;
    }
    $strip = static function (mixed $row) use (&$strip): mixed {
        if (!is_array($row)) {
            return $row;
        }
        unset($row['cost_price'], $row['total_cost']);
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $row[$k] = $strip($v);
            }
        }
        return $row;
    };
    return $strip($data);
};
$dateQ    = static fn (Request $r, string $k): ?string => $r->query($k) === null ? null : Validator::date([$k => $r->query($k)], $k, $k);

// ---- Authentication (blueprint step 2) ----
$router->add('GET', '/auth/status', fn () => ['needs_setup' => AuthService::needsSetup()], null, true);
$router->add('POST', '/auth/setup', fn (Request $r) => AuthService::setupOwner($r->body()), null, true);
$router->add('POST', '/auth/login', function (Request $r) {
    $b = $r->body();
    return AuthService::login((string) ($b['username'] ?? ''), (string) ($b['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}, null, true);
$router->add('POST', '/auth/logout', function () { AuthService::logout(); return ['logged_out' => true]; });
$router->add('GET', '/auth/me', fn (Request $r) => ['user' => AuthService::publicUser($r->user), 'csrf_token' => AuthService::csrfToken()]);

// ---- Products (step 3) ----
$router->add('GET', '/products', fn (Request $r) => $hideCost($r, ProductService::list($r->storeId(), $r->query('search'), $r->query('active', '1'), $limit($r), $offset($r), $r->query('sort'), $r->query('stock'))), 'products.view');
$router->add('GET', '/products/similar', fn (Request $r) => ProductService::findSimilar($r->storeId(), $r->query('name'), $r->query('barcode')), 'products.view');
$router->add('GET', '/products/{id}', fn (Request $r, array $p) => $hideCost($r, ProductService::find($r->storeId(), $intParam($p, 'id'))), 'products.view');
$router->add('POST', '/products', fn (Request $r) => ProductService::create($r->storeId(), $r->userId(), $r->user['role'], $r->body()), 'products.manage');
$router->add('PUT', '/products/{id}', fn (Request $r, array $p) => ProductService::update($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'products.manage');
$router->add('POST', '/products/{id}/deactivate', fn (Request $r, array $p) => ProductService::setActive($r->storeId(), $r->userId(), $intParam($p, 'id'), false), 'products.manage');
$router->add('POST', '/products/{id}/activate', fn (Request $r, array $p) => ProductService::setActive($r->storeId(), $r->userId(), $intParam($p, 'id'), true), 'products.manage');

// ---- Inventory ledger (step 4) ----
$router->add('GET', '/inventory/movements', function (Request $r) use ($limit, $offset, $dateQ) {
    $pid = $r->query('product_id') === null ? null : Validator::id(['id' => $r->query('product_id')], 'id', 'Product');
    return InventoryService::movements($r->storeId(), $pid, $dateQ($r, 'from'), $dateQ($r, 'to'), $limit($r), $offset($r));
}, 'inventory.view');
$router->add('POST', '/inventory/adjustments', fn (Request $r) => InventoryService::adjust($r->storeId(), $r->userId(), $r->user['role'], $r->body()), 'inventory.adjust');

// ---- Barcode (step 5) ----
$router->add('GET', '/barcode/{code}', fn (Request $r, array $p) => $hideCost($r, BarcodeService::lookup($r->storeId(), $p['code'], (string) $r->query('mode', 'sale'))), 'barcode.lookup');

// ---- Purchases (step 6) ----
$router->add('GET', '/purchases', function (Request $r) use ($limit, $offset, $dateQ) {
    return PurchaseService::list($r->storeId(), $dateQ($r, 'from'), $dateQ($r, 'to'), $limit($r), $offset($r));
}, 'purchases.view');
$router->add('GET', '/purchases/{id}', fn (Request $r, array $p) => PurchaseService::find($r->storeId(), $intParam($p, 'id')), 'purchases.view');
$router->add('POST', '/purchases', fn (Request $r) => PurchaseService::create($r->storeId(), $r->userId(), $r->body()), 'purchases.create');
$router->add('POST', '/purchases/{id}/cancel', fn (Request $r, array $p) => PurchaseService::cancel($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'purchases.cancel');
$router->add('POST', '/purchases/{id}/payments', fn (Request $r, array $p) => PurchaseService::addPayment($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'purchases.pay');

// ---- Sales (step 7) ----
$router->add('GET', '/sales/{id}', fn (Request $r, array $p) => $hideCost($r, SaleService::find($r->storeId(), $intParam($p, 'id'), $ownOnly($r))), 'sales.view');
$router->add('POST', '/sales', fn (Request $r) => $hideCost($r, SaleService::create($r->storeId(), $r->user, $r->body())), 'sales.create');

// ---- Returns and cancellations (step 11) ----
$router->add('POST', '/sales/{id}/returns', fn (Request $r, array $p) => ReturnService::create($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'returns.create');

// ---- Cash and capital (step 8) ----
$router->add('GET', '/cash/summary', fn (Request $r) => CashService::summary($r->storeId()), 'cash.view');
$router->add('GET', '/cash/ledger', fn (Request $r) => CashService::ledger($r->storeId(), $dateQ($r, 'from'), $dateQ($r, 'to'), $limit($r), $offset($r)), 'cash.view');
$router->add('PUT', '/cash/reserve', fn (Request $r) => CashService::setReserve($r->storeId(), $r->userId(), $r->body()), 'cash.manage');
$router->add('POST', '/cash/withdrawals', fn (Request $r) => CashService::withdraw($r->storeId(), $r->userId(), $r->body()), 'cash.manage');
$router->add('POST', '/cash/withdrawals/{id}/void', fn (Request $r, array $p) => CashService::voidWithdrawal($r->storeId(), $r->userId(), $intParam($p, 'id')), 'cash.manage');
$router->add('POST', '/cash/counts', fn (Request $r) => CashService::recordCount($r->storeId(), $r->userId(), $r->body()), 'cash.manage');

// ---- Credit / utang (step 9) ----
$router->add('GET', '/credits', fn (Request $r) => CreditService::list($r->storeId(), (string) $r->query('status', 'open'), $r->query('search'), $limit($r), $offset($r)), 'credits.view');
$router->add('GET', '/credits/{id}', fn (Request $r, array $p) => CreditService::find($r->storeId(), $intParam($p, 'id')), 'credits.view');
$router->add('POST', '/credits/{id}/payments', fn (Request $r, array $p) => CreditService::pay($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'credits.pay');
$router->add('POST', '/credit-payments/{id}/void', fn (Request $r, array $p) => CreditService::voidPayment($r->storeId(), $r->userId(), $intParam($p, 'id')), 'credits.pay');

// ---- Expenses (step 10) ----
$router->add('GET', '/expenses/categories', fn (Request $r) => ExpenseService::categories($r->storeId()), 'expenses.view');
$router->add('GET', '/expenses', fn (Request $r) => ExpenseService::list($r->storeId(), $dateQ($r, 'from'), $dateQ($r, 'to'), $r->query('include_voided') === '1', $limit($r), $offset($r)), 'expenses.view');
$router->add('POST', '/expenses', fn (Request $r) => ExpenseService::create($r->storeId(), $r->userId(), $r->body()), 'expenses.manage');
$router->add('POST', '/expenses/{id}/void', fn (Request $r, array $p) => ExpenseService::void($r->storeId(), $r->userId(), $intParam($p, 'id')), 'expenses.manage');

// ---- Reports, dashboard and activity (step 12) ----
$router->add('GET', '/reports/summary', function (Request $r) {
    [$from, $to] = ReportService::range($r->query('from'), $r->query('to'));
    return ReportService::periodSummary($r->storeId(), $from, $to);
}, 'reports.view');
$router->add('GET', '/reports/sales', function (Request $r) {
    [$from, $to] = ReportService::range($r->query('from'), $r->query('to'));
    $granularity = (string) $r->query('granularity', 'day');
    if (!in_array($granularity, ['day', 'week'], true)) {
        throw Validator::fail('Granularity must be day or week.');
    }
    return ReportService::salesSeries($r->storeId(), $from, $to, $granularity);
}, 'reports.view');
$router->add('GET', '/reports/stock', fn (Request $r) => ReportService::stock($r->storeId(), $limit($r), $offset($r)), 'reports.view');
$router->add('GET', '/reports/credits', fn (Request $r) => ReportService::credits($r->storeId()), 'reports.view');
$router->add('GET', '/dashboard', fn (Request $r) => ReportService::dashboard($r->storeId()), 'reports.view');
$router->add('GET', '/activity', function (Request $r) use ($limit, $offset, $dateQ, $ownOnly) {
    $pid = $r->query('product_id') === null ? null : Validator::id(['id' => $r->query('product_id')], 'id', 'Product');
    return ActivityService::feed($r->storeId(), $dateQ($r, 'from'), $dateQ($r, 'to'), $r->query('type'), $limit($r), $offset($r), $ownOnly($r), $pid, $r->query('payment_method'));
}, 'activity.view');

// ---- Notifications, notes/tasks, profile and settings (steps 13 and beyond) ----
$router->add('GET', '/notifications', fn (Request $r) => NotificationService::stockAlerts($r->storeId()), 'inventory.view');

$router->add('GET', '/notes', fn (Request $r) => NotesService::list($r->storeId(), (string) $r->query('status', 'active'), $limit($r), $offset($r)), 'notes.manage');
$router->add('POST', '/notes', fn (Request $r) => NotesService::create($r->storeId(), $r->userId(), $r->body()), 'notes.manage');
$router->add('PUT', '/notes/{id}', fn (Request $r, array $p) => NotesService::update($r->storeId(), $intParam($p, 'id'), $r->body()), 'notes.manage');
$router->add('POST', '/notes/{id}/complete', fn (Request $r, array $p) => NotesService::setStatus($r->storeId(), $intParam($p, 'id'), 'done'), 'notes.manage');
$router->add('POST', '/notes/{id}/reopen', fn (Request $r, array $p) => NotesService::setStatus($r->storeId(), $intParam($p, 'id'), 'open'), 'notes.manage');
$router->add('POST', '/notes/{id}/archive', fn (Request $r, array $p) => NotesService::setStatus($r->storeId(), $intParam($p, 'id'), 'archived'), 'notes.manage');

$router->add('GET', '/profile', fn (Request $r) => ProfileService::get($r->user));
$router->add('PUT', '/profile/store', fn (Request $r) => ProfileService::updateStore($r->storeId(), $r->userId(), $r->body()), 'settings.manage');
$router->add('PUT', '/profile/account', fn (Request $r) => ProfileService::updateAccount($r->user, $r->body()));
$router->add('POST', '/profile/password', fn (Request $r) => ProfileService::changePassword($r->user, $r->body()));
$router->add('PUT', '/settings', fn (Request $r) => ProfileService::updateSettings($r->storeId(), $r->userId(), $r->body()), 'settings.manage');

// ---- Staff accounts and CSV export ----
$router->add('GET', '/users', fn (Request $r) => UserService::list($r->storeId()), 'users.manage');
$router->add('POST', '/users', fn (Request $r) => UserService::create($r->storeId(), $r->userId(), $r->body()), 'users.manage');
$router->add('PUT', '/users/{id}', fn (Request $r, array $p) => UserService::update($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'users.manage');
$router->add('POST', '/users/{id}/password', fn (Request $r, array $p) => UserService::resetPassword($r->storeId(), $r->userId(), $intParam($p, 'id'), $r->body()), 'users.manage');
$router->add('POST', '/users/{id}/deactivate', fn (Request $r, array $p) => UserService::setActive($r->storeId(), $r->userId(), $intParam($p, 'id'), false), 'users.manage');
$router->add('POST', '/users/{id}/activate', fn (Request $r, array $p) => UserService::setActive($r->storeId(), $r->userId(), $intParam($p, 'id'), true), 'users.manage');
$router->add('GET', '/export/{type}', function (Request $r, array $p) {
    if (!in_array($p['type'], ExportService::types(), true)) {
        throw new CapiTally\Support\AppException('Unknown export type.', 'NOT_FOUND', 404);
    }
    [$from, $to] = ReportService::range($r->query('from'), $r->query('to'));
    ExportService::download($r->storeId(), $r->userId(), $p['type'], $from, $to);
}, 'export.data');

return $router;
