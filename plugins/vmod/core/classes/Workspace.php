<?php namespace Vmod\Core\Classes;

use Session;
use Request;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\User;

class Workspace
{
    public static function token(): string
    {
        if (!Session::has('vmod.context')) {
            Session::put('vmod.context', bin2hex(random_bytes(32)));
        }
        return Session::get('vmod.context');
    }

    public static function clear(): void
    {
        Session::forget(['vmod.company_id', 'vmod.branch_id', 'vmod.context']);
    }

    public static function assertContext(): void
    {
        $expected = Session::get('vmod.context');
        $submitted = Request::header('X-Workspace-Context');
        abort_unless(is_string($expected) && is_string($submitted) && hash_equals($expected, $submitted), 409, 'Workspace changed. Reload this page before saving.');
    }

    public static function switchTo(int $companyId, ?int $branchId = null): void
    {
        $member = self::findMembership($companyId);
        abort_unless($member, 404);
        abort_if($branchId && !in_array($branchId, $member->branchIds(), true), 404);
        Session::put('vmod.company_id', $companyId);
        Session::put('vmod.branch_id', $branchId);
        Session::put('vmod.context', bin2hex(random_bytes(32)));
    }

    public static function membership(): Membership
    {
        $member = self::findMembership((int) Session::get('vmod.company_id'));
        abort_unless($member, 403, 'You do not have access to this workspace.');
        $branchId = self::branchId();
        abort_if($branchId && !in_array($branchId, $member->branchIds(), true), 403);
        return $member;
    }

    public static function branchId(): ?int
    {
        $id = Session::get('vmod.branch_id');
        return $id ? (int) $id : null;
    }

    public static function authorize(string $permission): Membership
    {
        $member = self::membership();
        abort_unless(in_array($permission, Permissions::all(), true)
            && in_array($permission, $member->role->permissions ?? [], true), 403, 'You do not have permission for this action.');
        return $member;
    }

    public static function hasAccess(string $permission): bool
    {
        try {
            self::authorize($permission);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return false;
        }
    }

    public static function memberships()
    {
        $user = Auth::getUser();
        return Membership::with(['company', 'role', 'branches'])->where('user_id', $user ? $user->id : 0)->where('is_active', true)->get();
    }

    private static function findMembership(int $companyId): ?Membership
    {
        $actor = Auth::getUser();
        $user = $actor ? User::find($actor->id) : null;
        if (!$user || $user->is_banned) {
            return null;
        }
        $member = Membership::with('role')->where('company_id', $companyId)->where('user_id', $user->id)->where('is_active', true)->first();
        return $member && $member->role && (int) $member->role->company_id === $companyId ? $member : null;
    }
}
