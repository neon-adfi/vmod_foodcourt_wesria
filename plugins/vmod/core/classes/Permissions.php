<?php namespace Vmod\Core\Classes;

class Permissions
{
    public static function all(): array
    {
        return [
            'workspace.manage', 'users.manage', 'roles.manage', 'catalogue.read', 'catalogue.write',
            'purchaseorder.read', 'purchaseorder.write', 'purchaseorder.approve', 'purchaseorder.close',
            'goodsreceipt.read', 'goodsreceipt.write', 'goodsreceipt.confirm', 'goodsreceipt.correct',
            'salesorder.read', 'salesorder.write', 'salesorder.approve', 'salesorder.close',
            'deliveryorder.read', 'deliveryorder.write', 'deliveryorder.dispatch', 'deliveryorder.correct',
            'inventory.read', 'inventory.adjust', 'invoice_reference.read', 'invoice_reference.write',
        ];
    }
}
