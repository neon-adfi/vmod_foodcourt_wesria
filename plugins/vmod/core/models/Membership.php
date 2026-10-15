<?php namespace Vmod\Core\Models;

class Membership extends \Model
{
    public $table = 'vmod_core_memberships';
    protected $fillable = ['company_id', 'user_id', 'role_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public $belongsTo = [
        'user' => [User::class, 'key' => 'user_id'],
        'role' => [Role::class, 'key' => 'role_id'],
        'company' => [Company::class, 'key' => 'company_id'],
    ];
    public $belongsToMany = [
        'branches' => [
            Branch::class, 'table' => 'vmod_core_membership_branches',
            'key' => 'membership_id', 'otherKey' => 'branch_id',
        ],
    ];

    public function branchIds(): array
    {
        return $this->branches()->where('company_id', $this->company_id)->pluck('vmod_core_branches.id')->map('intval')->all();
    }

    public function isFullAdministrator(): bool
    {
        return $this->is_active
            && $this->user && !$this->user->is_banned
            && $this->role && (int) $this->role->company_id === (int) $this->company_id
            && !array_diff(\Vmod\Core\Classes\Permissions::all(), $this->role->permissions ?? [])
            && !array_diff(Branch::where('company_id', $this->company_id)->pluck('id')->all(), $this->branchIds());
    }
}
