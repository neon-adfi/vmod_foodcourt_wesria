<?php namespace Vmod\Circularity\Models;

class Area extends \Model
{
    public $table = 'vmod_circularity_areas';
    protected $fillable = ['name', 'kind', 'is_active'];
    protected $casts = ['id' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];

    public function scopeForContext($query, array $context)
    {
        return $query->where('source_namespace', $context['source_namespace'])
            ->where('tenant_ref', $context['tenant_ref'])->whereIn('branch_ref', $context['branch_refs']);
    }
}
