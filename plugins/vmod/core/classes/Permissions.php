<?php namespace Vmod\Core\Classes;

class Permissions
{
    public static function all(): array
    {
        $permissions = [
            'workspace.manage', 'users.manage', 'roles.manage', 'catalogue.read', 'catalogue.write',
            'purchaseorder.read', 'purchaseorder.write', 'purchaseorder.approve', 'purchaseorder.close',
            'goodsreceipt.read', 'goodsreceipt.write', 'goodsreceipt.confirm', 'goodsreceipt.correct',
            'salesorder.read', 'salesorder.write', 'salesorder.approve', 'salesorder.close',
            'deliveryorder.read', 'deliveryorder.write', 'deliveryorder.dispatch', 'deliveryorder.correct',
            'inventory.read', 'inventory.adjust', 'invoice_reference.read', 'invoice_reference.write',
        ];
        foreach (\Event::fire('vmod.core.extendPermissions') as $additional) {
            if ($additional === null) {
                continue;
            }
            if (!is_array($additional)) {
                throw new \UnexpectedValueException('Permission extensions must return arrays.');
            }
            foreach ($additional as $permission) {
                if (!is_string($permission) || !preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/', $permission)) {
                    throw new \UnexpectedValueException('Invalid permission extension.');
                }
                $permissions[] = $permission;
            }
        }
        return array_values(array_unique($permissions));
    }
}
