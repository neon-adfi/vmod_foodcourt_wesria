<?php namespace Vmod\Core\Models;

class Audit extends \Model
{
    public $table = 'vmod_core_audits';
    public $timestamps = false;
    protected $fillable = ['company_id', 'actor_user_id', 'source', 'action', 'details', 'created_at'];
    protected $jsonable = ['details'];

    public static function record(int $companyId, string $action, array $details, ?int $actorId, string $source = 'frontend'): void
    {
        static::create([
            'company_id' => $companyId, 'actor_user_id' => $actorId, 'source' => $source,
            'action' => $action, 'details' => $details, 'created_at' => now(),
        ]);
    }
}
