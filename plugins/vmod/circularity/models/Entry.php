<?php namespace Vmod\Circularity\Models;

class Entry extends \Model
{
    use \October\Rain\Database\Traits\SoftDelete;

    public $table = 'vmod_circularity_entries';
    protected $fillable = ['area_id', 'measurement_date', 'measurement_type', 'mode', 'quantity', 'destination', 'reference', 'notes'];
    protected $casts = ['id' => 'integer', 'area_id' => 'integer', 'version' => 'integer'];

    public function scopeForContext($query, array $context)
    {
        return $query->where('source_namespace', $context['source_namespace'])
            ->where('tenant_ref', $context['tenant_ref'])->whereIn('branch_ref', $context['branch_refs']);
    }
}
