<?php namespace Vmod\Core\Services;

use DB;
use Mail;
use Validator;
use October\Rain\Exception\ApplicationException;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Role;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\Invitation;
use Vmod\Core\Models\User;
use Vmod\Core\Models\Audit;

class InvitationService
{
    public function issue(array $input): array
    {
        $data = Validator::make($input, [
            'email' => 'required|email|max:255', 'role_id' => 'required|integer|min:1',
            'branch_ids' => 'present|array', 'branch_ids.*' => 'integer|min:1|distinct',
        ])->validate();
        $data['email'] = strtolower(trim($data['email']));
        $admin = new AdministrationService;
        $issued = $admin->mutate('users.manage', function ($actor) use ($data, $admin) {
            $role = Role::where('company_id', $actor->company_id)->findOrFail($data['role_id']);
            $branchIds = array_map('intval', $data['branch_ids']);
            $admin->assertGrantScope($actor, $role, $branchIds);
            $user = User::withTrashed()->where('email', $data['email'])->first();
            abort_if($user && Membership::where('company_id', $actor->company_id)->where('user_id', $user->id)->exists(), 409, 'This person is already a member. Manage their existing membership.');
            $pending = Invitation::pending()->where('company_id', $actor->company_id)->where('email', $data['email'])->get();
            foreach ($pending as $old) {
                $admin->assertGrantScope($actor, $old->role, $old->branch_ids);
                $old->update(['revoked_at' => now()]);
            }
            $token = bin2hex(random_bytes(32));
            $invitation = Invitation::create([
                'company_id' => $actor->company_id, 'role_id' => $role->id,
                'intended_user_id' => $user ? $user->id : null, 'created_by_user_id' => $actor->user_id,
                'email' => $data['email'], 'branch_ids' => $branchIds,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays((int) config('vmod.core::invitation_days', 7)),
            ]);
            Audit::record($actor->company_id, 'invitation.issued', [
                'invitation_id' => $invitation->id, 'role_id' => $role->id,
                'branch_ids' => $branchIds, 'replaced_ids' => $pending->pluck('id')->all(),
            ], $actor->user_id);
            return compact('invitation', 'token');
        });
        // Sending follows commit. A transport failure preserves a pending invitation for a deliberate resend.
        try {
            Mail::send('vmod.core::mail.invitation', [
                'company' => $issued['invitation']->company->name,
                'url' => rtrim(config('app.url'), '/') . '/invitations/' . $issued['token'],
                'expires' => $issued['invitation']->expires_at->format('d M Y H:i'),
            ], function ($message) use ($issued) {
                $message->to($issued['invitation']->email);
            });
        } catch (\Throwable $exception) {
            // Omit raw messages/trace arguments: mail exceptions may contain credentials or invitation tokens.
            \Log::error('[InvitationService] Invitation mail failed.', [
                'invitation_id' => $issued['invitation']->id, 'exception' => get_class($exception),
            ]);
            throw new ApplicationException('Invitation saved, but email could not be sent. Use Resend to try again.');
        }
        return $issued;
    }

    public function revoke(int $id): void
    {
        $admin = new AdministrationService;
        $admin->mutate('users.manage', function ($actor) use ($id, $admin) {
            $invitation = Invitation::where('company_id', $actor->company_id)->findOrFail($id);
            $admin->assertGrantScope($actor, $invitation->role, $invitation->branch_ids);
            abort_if($invitation->accepted_at || $invitation->revoked_at, 409, 'This invitation is no longer pending.');
            $invitation->update(['revoked_at' => now()]);
            Audit::record($actor->company_id, 'invitation.revoked', ['invitation_id' => $id], $actor->user_id);
        });
    }

    public function inspect(string $token): Invitation
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $invitation = Invitation::with(['company', 'role'])->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast(), 409, 'This invitation is no longer available. Ask your company administrator for a new invitation.');
        return $invitation;
    }

    public function accept(string $token, array $input): User
    {
        $preview = $this->inspect($token);
        return IdentityService::withAccountCreationLock(function () use ($preview, $token, $input) {
            return DB::transaction(function () use ($preview, $token, $input) {
                Company::whereKey($preview->company_id)->lockForUpdate()->firstOrFail();
                $invitation = $this->inspect($token);
                $role = Role::where('company_id', $invitation->company_id)->findOrFail($invitation->role_id);
                $branchIds = array_map('intval', $invitation->branch_ids);
                abort_if(array_diff($branchIds, Branch::where('company_id', $invitation->company_id)->pluck('id')->all()), 409);
                // The issued company invitation remains authority; later issuer revocation does not invalidate it.
                $user = User::withTrashed()->where('email', $invitation->email)->first();
                $actor = Auth::getUser();
                if ($user) {
                    abort_unless(
                        $actor && !$user->trashed() && !$user->is_banned
                        && (int) $actor->id === (int) $user->id
                        && (!$invitation->intended_user_id || (int) $invitation->intended_user_id === (int) $actor->id),
                        403,
                        'Sign in as the invited account to accept this invitation.'
                    );
                } else {
                    abort_if($actor || $invitation->intended_user_id, 403, 'Sign out and use the intended invited account.');
                    $data = Validator::make($input, [
                        'first_name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255',
                        'password' => 'required|string|confirmed',
                    ])->validate();
                    $user = new User;
                    $user->first_name = $data['first_name'];
                    $user->last_name = $data['last_name'] ?? null;
                    $user->email = $invitation->email;
                    $user->password = $data['password'];
                    $user->password_confirmation = $input['password_confirmation'];
                    $user->activated_at = now();
                    $user->save();
                }
                abort_if(Membership::where('company_id', $invitation->company_id)->where('user_id', $user->id)->exists(), 409, 'This membership already exists.');
                $member = Membership::create(['company_id' => $invitation->company_id, 'user_id' => $user->id, 'role_id' => $role->id]);
                $member->branches()->sync($branchIds);
                $invitation->update(['accepted_at' => now(), 'intended_user_id' => $user->id]);
                Audit::record($invitation->company_id, 'invitation.accepted', ['invitation_id' => $invitation->id, 'membership_id' => $member->id], $user->id, 'invitation');
                return $user;
            });
        });
    }
}
