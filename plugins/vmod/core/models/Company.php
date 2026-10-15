<?php namespace Vmod\Core\Models;

class Company extends \Model
{
    public $table = 'vmod_core_companies';
    protected $fillable = ['code', 'name'];
}
