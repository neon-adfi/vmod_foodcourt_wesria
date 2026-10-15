<?php namespace Vmod\Core\Models;

class Invitation extends \Model
{
    public $table = 'vmod_core_invitations';
    protected $fillable = [
        'company_id', 'role_id', 'intended_user_id', 'created_by_user_id', 'email', 'branch_ids',
        'token_hash', 'expires_at', 'accepted_at', 'revoked_at',
    ];
    protected $jsonable = ['branch_ids'];
    protected $dates = ['expires_at', 'accepted_at', 'revoked_at'];
    protected $hidden = ['token_hash'];
    public $belongsTo = [
        'company' => [Company::class, 'key' => 'company_id'],
        'role' => [Role::class, 'key' => 'role_id'],
    ];

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
