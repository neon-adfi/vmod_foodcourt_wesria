<?php

// Additive, owned-local campaign fixtures. --snapshot is read-only; never run migrations here.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bootstrap = new \Vmod\Core\Services\BootstrapService;
$bootstrap->assertOwnedEnvironment();
if (!$app->environment('local') || !Schema::hasTable('vmod_erp_invoice_references')) {
    throw new RuntimeException('The already-migrated owned local runtime is required.');
}
$mode = $argv[1] ?? '';
if ($mode !== '--create') {
    $fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $ids = [];
    foreach (['a', 'b', 'c'] as $key) {
        $company = \Vmod\Core\Models\Company::findOrFail($fixture[$key]['company_id']);
        if ($company->code !== 'unhappy-' . $key . '-' . $fixture['nonce']) {
            throw new RuntimeException('Fixture ownership mismatch.');
        }
        $ids[] = $company->id;
    }
    if ($mode === '--legacy') {
        $result = DB::transaction(function () use ($fixture) {
            $source = \Vmod\Erp\Models\PurchaseOrder::where('company_id', $fixture['a']['company_id'])->findOrFail($fixture['po_id']);
            $result = [];
            foreach (['legacy' => 60, 'currency' => 1] as $kind => $count) {
                $number = 'PO-UAT-' . strtoupper($kind) . '-' . $fixture['nonce'];
                $order = \Vmod\Erp\Models\PurchaseOrder::where('company_id', $source->company_id)->where('number', $number)->first();
                if (!$order) {
                    $order = $source->replicate();
                    $order->number = $number;
                    $order->revision_id = null;
                    $order->state = $kind === 'legacy' ? 'draft' : 'approved';
                    $order->first_received_at = null;
                    $order->save();
                    $revision = $source->revision->replicate();
                    $revision->purchase_order_id = $order->id;
                    $revision->issuer_snapshot = $kind === 'legacy' ? null : $source->revision->issuer_snapshot;
                    $revision->currency = $kind === 'currency' ? 'USD' : 'MYR';
                    $revision->approved_at = $kind === 'legacy' ? null : now();
                    $revision->created_at = now();
                    $revision->net = $revision->gross = (string) ($count * 10);
                    $revision->tax = '0';
                    $revision->save();
                    for ($i = 1; $i <= $count; $i++) {
                        $item = $source->revision->items->first()->replicate();
                        $item->revision_id = $revision->id;
                        $item->position = $i;
                        $item->quantity = $item->base_quantity = '1';
                        $item->net = $item->gross = '10';
                        $item->tax = '0';
                        $item->save();
                    }
                    $order->revision_id = $revision->id;
                    $order->save();
                }
                $result[$kind . '_po_id'] = $order->id;
            }
            $number = 'GRN-UAT-LEGACY-' . $fixture['nonce'];
            $receipt = \Vmod\Erp\Models\GoodsReceipt::where('company_id', $source->company_id)->where('number', $number)->first();
            if (!$receipt) {
                $parent = \Vmod\Erp\Models\PurchaseOrder::findOrFail($result['currency_po_id']);
                $receipt = new \Vmod\Erp\Models\GoodsReceipt;
                $receipt->company_id = $parent->company_id;
                $receipt->branch_id = $parent->branch_id;
                $receipt->purchase_order_id = $parent->id;
                $receipt->revision_id = $parent->revision_id;
                $receipt->number = $number;
                $receipt->state = 'draft';
                $receipt->supplier_reference = 'SYNTHETIC-LEGACY-' . $fixture['nonce'];
                $receipt->document_date = date('Y-m-d');
                $receipt->save();
                $receipt->root_id = $receipt->id;
                $receipt->save();
                \Vmod\Erp\Models\GoodsReceiptItem::create(['goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $parent->revision->items->first()->id,
                    'location_id' => $fixture['location_id'], 'location_name' => null, 'quantity' => '1', 'base_quantity' => '1']);
            }
            $result['legacy_grn_id'] = $receipt->id;
            return $result;
        });
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($mode === '--expired-reset') {
        $user = \Vmod\Core\Models\User::findOrFail($fixture['recovery']['user_id']);
        if ($user->email !== 'unhappy-recovery-' . $fixture['nonce'] . '@example.invalid'
            || DB::table('user_password_resets')->where('email', $user->email)->exists()) {
            throw new RuntimeException('Only a fresh campaign recovery account may receive an expired token fixture.');
        }
        $token = bin2hex(random_bytes(32));
        DB::table('user_password_resets')->insert(['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()->subHours(2)]);
        echo json_encode(['url' => '/auth/reset?reset=' . $token . '&email=' . urlencode($user->email)], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($mode === '--snapshot') {
        $state = [];
        $tables = DB::select("SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id' AND TABLE_NAME LIKE 'vmod_%' ORDER BY TABLE_NAME");
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table->name);
            $query = DB::table($table->name)->whereIn('company_id', $ids);
            $state[$table->name] = $query->orderBy(in_array('id', $columns, true) ? 'id' : 'company_id')->get()->all();
        }
        foreach (['purchase', 'sales'] as $kind) {
            $parents = DB::table('vmod_erp_' . $kind . '_orders')->whereIn('company_id', $ids)->pluck('id');
            $revisions = DB::table('vmod_erp_' . $kind . '_order_revisions')->whereIn($kind . '_order_id', $parents)->orderBy('id')->get();
            $state['vmod_erp_' . $kind . '_order_revisions'] = $revisions->all();
            $state['vmod_erp_' . $kind . '_order_items'] = DB::table('vmod_erp_' . $kind . '_order_items')->whereIn('revision_id', $revisions->pluck('id'))->orderBy('id')->get()->all();
        }
        foreach (['goods_receipt' => 'goods_receipts', 'delivery_order' => 'delivery_orders', 'invoice_reference' => 'invoice_references'] as $kind => $parentTable) {
            $parents = DB::table('vmod_erp_' . $parentTable)->whereIn('company_id', $ids)->pluck('id');
            $table = 'vmod_erp_' . $kind . ($kind === 'invoice_reference' ? '_links' : '_items');
            $state[$table] = DB::table($table)->whereIn($kind . '_id', $parents)->orderBy('id')->get()->all();
        }
        $state['vmod_core_companies'] = DB::table('vmod_core_companies')->whereIn('id', $ids)->orderBy('id')->get()->all();
        $members = DB::table('vmod_core_memberships')->whereIn('company_id', $ids)->pluck('id');
        $state['vmod_core_membership_branches'] = DB::table('vmod_core_membership_branches')->whereIn('membership_id', $members)->orderBy('membership_id')->orderBy('branch_id')->get()->all();
        $state['campaign_users'] = DB::table('users')->where('email', 'like', '%-' . $fixture['nonce'] . '@example.invalid')
            ->select(['id', 'email', 'first_name', 'last_name', 'banned_at', 'activated_at'])
            ->selectRaw('SHA2(password, 256) AS credential_fingerprint')->orderBy('id')->get()->all();
        $errors = [];
        $knownBalances = [];
        foreach (DB::table('vmod_erp_stock_balances')->whereIn('company_id', $ids)->get() as $balance) {
            $key = ['company_id' => $balance->company_id, 'branch_id' => $balance->branch_id, 'location_id' => $balance->location_id, 'product_id' => $balance->product_id];
            $knownBalances[implode('|', $key)] = true;
            $physical = DB::table('vmod_erp_stock_movements')->where($key)->sum('quantity');
            $reserved = DB::table('vmod_erp_stock_reservations')->where($key)->selectRaw('COALESCE(SUM(approved - dispatched - released), 0) AS total')->first()->total;
            $on = \Brick\Math\BigDecimal::of($balance->on_hand);
            $res = \Brick\Math\BigDecimal::of($balance->reserved);
            if (!$on->isEqualTo($physical) || !$res->isEqualTo($reserved) || $on->isLessThan($res) || $res->isNegative()) {
                $errors[] = ['balance_id' => $balance->id, 'on_hand' => $balance->on_hand, 'reserved' => $balance->reserved, 'ledger' => (string) $physical, 'reservation_sum' => (string) $reserved];
            }
        }
        foreach (array_merge($state['vmod_erp_stock_movements'], $state['vmod_erp_stock_reservations']) as $entry) {
            $key = implode('|', [$entry->company_id, $entry->branch_id, $entry->location_id, $entry->product_id]);
            if (!isset($knownBalances[$key])) {
                $errors[] = ['missing_balance' => $key];
            }
        }
        echo json_encode(['tables' => $state, 'reconciliation_errors' => $errors], JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    throw new RuntimeException('Unknown fixture mode.');
}
$nonce = bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(20)) . '7!';
$fixture = ['nonce' => $nonce, 'password' => $password, 'baseline' => '478f4a7575b8a85501761d6c01e7241a0b01d361',
    'runtime' => ['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS v')->v, 'database' => DB::connection()->getDatabaseName(), 'host' => config('database.connections.mysql.host'), 'theme' => config('cms.active_theme')]];
DB::transaction(function () use (&$fixture, $nonce, $password, $bootstrap) {
    foreach (['a', 'b', 'c'] as $key) {
        $created = $bootstrap->create(['company_code' => 'unhappy-' . $key . '-' . $nonce, 'company_name' => 'Unhappy ' . strtoupper($key) . ' ' . $nonce,
            'branch_name' => 'Main', 'first_name' => 'Owner ' . strtoupper($key), 'email' => 'unhappy-' . $key . '-' . $nonce . '@example.invalid', 'password' => $password, 'password_confirmation' => $password]);
        $fixture[$key] = ['email' => $created['owner']->email, 'user_id' => $created['owner']->id, 'company_id' => $created['company']->id,
            'branch_id' => $created['branch']->id, 'member_id' => $created['membership']->id, 'role_id' => $created['membership']->role_id];
    }
    $branch = \Vmod\Core\Models\Branch::create(['company_id' => $fixture['a']['company_id'], 'name' => 'Second branch']);
    \Vmod\Core\Models\Membership::findOrFail($fixture['a']['member_id'])->branches()->attach($branch->id);
    $fixture['second_branch_id'] = $branch->id;
    $all = \Vmod\Core\Classes\Permissions::all();
    $sets = [
        'operator' => array_values(array_diff($all, ['workspace.manage', 'users.manage', 'roles.manage'])),
        'clerk' => array_values(array_filter($all, fn ($p) => str_ends_with($p, '.read') || str_ends_with($p, '.write'))),
        'manager' => ['workspace.manage', 'users.manage', 'roles.manage', 'catalogue.read'],
        'invoice' => ['invoice_reference.read', 'invoice_reference.write'],
        'recovery' => ['catalogue.read', 'catalogue.write'],
    ];
    foreach ($sets as $name => $permissions) {
        $role = \Vmod\Core\Models\Role::create(['company_id' => $fixture['a']['company_id'], 'name' => ucfirst($name), 'permissions' => $permissions]);
        $user = new \Vmod\Core\Models\User;
        $user->first_name = ucfirst($name);
        $user->email = 'unhappy-' . $name . '-' . $nonce . '@example.invalid';
        $user->password = $password;
        $user->password_confirmation = $password;
        $user->activated_at = now();
        $user->save();
        $member = \Vmod\Core\Models\Membership::create(['company_id' => $fixture['a']['company_id'], 'user_id' => $user->id, 'role_id' => $role->id]);
        $member->branches()->attach($fixture['a']['branch_id']);
        $fixture[$name] = ['email' => $user->email, 'user_id' => $user->id, 'company_id' => $fixture['a']['company_id'], 'branch_id' => $fixture['a']['branch_id'], 'member_id' => $member->id, 'role_id' => $role->id, 'permissions' => $permissions];
    }
    $token = bin2hex(random_bytes(32));
    $expired = \Vmod\Core\Models\Invitation::create(['company_id' => $fixture['a']['company_id'], 'role_id' => $fixture['clerk']['role_id'], 'created_by_user_id' => $fixture['a']['user_id'],
        'email' => 'unhappy-expired-' . $nonce . '@example.invalid', 'branch_ids' => [$fixture['a']['branch_id']], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->subDay()]);
    $fixture['expired_invitation'] = ['id' => $expired->id, 'token' => $token];
});
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
