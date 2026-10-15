<?php namespace Vmod\Core\Models;

class Role extends \Model
{
    public $table = 'vmod_core_roles';
    protected $fillable = ['company_id', 'name', 'permissions'];
    protected $jsonable = ['permissions'];
}
