<?php namespace Vmod\CircularityUi\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Vmod\Core\Models\Audit;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Role;

class GrantCircularityPermissions extends Migration
{
    public function up()
    {
        // Freeze eligibility at the preceding registry; the live registry already includes this plugin.
        $preceding = [
            'workspace.manage', 'users.manage', 'roles.manage', 'catalogue.read', 'catalogue.write',
            'purchaseorder.read', 'purchaseorder.write', 'purchaseorder.approve', 'purchaseorder.close',
            'goodsreceipt.read', 'goodsreceipt.write', 'goodsreceipt.confirm', 'goodsreceipt.correct',
            'salesorder.read', 'salesorder.write', 'salesorder.approve', 'salesorder.close',
            'deliveryorder.read', 'deliveryorder.write', 'deliveryorder.dispatch', 'deliveryorder.correct',
            'inventory.read', 'inventory.adjust', 'invoice_reference.read', 'invoice_reference.write',
        ];
        Company::orderBy('id')->eachById(function ($company) use ($preceding) {
            DB::transaction(function () use ($company, $preceding) {
                Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                foreach (Role::where('company_id', $company->id)->get() as $role) {
                    $before = $role->permissions ?? [];
                    if (array_diff($preceding, $before)) {
                        continue;
                    }
                    $after = array_values(array_unique(array_merge($before, ['circularity.read', 'circularity.write'])));
                    if ($before === $after) {
                        continue;
                    }
                    $role->permissions = $after;
                    $role->save();
                    Audit::record($company->id, 'circularity.permissions_added', [
                        'role_id' => $role->id, 'before' => $before, 'after' => $after,
                        'migration' => 'Vmod.CircularityUi:1.0.1',
                    ], null, 'migration');
                }
            });
        });
    }

    public function down()
    {
        // Retain grants and their audit: rollback cannot distinguish later intentional grants.
        // Unregistered permissions grant no access; reactivation requires an administrator preflight.
    }
}
