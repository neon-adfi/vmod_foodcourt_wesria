<?php namespace Vmod\Core\Models;

class Branch extends \Model
{
    public $table = 'vmod_core_branches';
    protected $fillable = ['company_id', 'name'];
}
