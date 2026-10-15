<?php namespace Vmod\Core\Facades;

use Vmod\Core\Models\User;

class Auth
{
    public static function getUser(): ?User
    {
        $user = \Auth::user();
        return $user instanceof User ? $user : null;
    }
}
