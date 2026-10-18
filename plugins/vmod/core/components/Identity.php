<?php namespace Vmod\Core\Components;

use Flash;
use Redirect;
use Session;
use Validator;
use Cms\Classes\ComponentBase;
use RainLab\User\Classes\ActionManager;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\User;
use Vmod\Core\Services\IdentityService;
use Vmod\Core\Services\InvitationService;

class Identity extends ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Identity', 'description' => 'Native frontend account actions'];
    }

    public function defineProperties()
    {
        return ['view' => ['default' => 'login', 'type' => 'string']];
    }

    public function onRun()
    {
        $this->page['currentUser'] = Auth::getUser();
        $this->page['workspaceToken'] = Auth::getUser() ? Workspace::token() : null;
        if ($this->property('view') === 'account' && !Auth::getUser()) {
            return Redirect::to('/auth');
        }
        if ($this->property('view') === 'invitation') {
            $token = (string) $this->param('token');
            try {
                $invitation = (new InvitationService)->inspect($token);
                $account = User::withTrashed()->where('email', $invitation->email)->first();
                $this->page['invitation'] = $invitation;
                $this->page['accountExists'] = (bool) $account;
                $this->page['intendedAccount'] = Auth::getUser() && $account && (int) Auth::getUser()->id === (int) $account->id;
                Session::put('vmod.invitation', $token);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->page['invitationError'] = 'This invitation is unavailable. Ask your company administrator for a new invitation.';
            }
        }
    }

    public function onCheckContext(): array
    {
        Workspace::assertContext();
        return ['valid' => true];
    }

    public function onLogin()
    {
        $result = ActionManager::instance()->loginWithTwoFactor(post(), ['remember' => false]);
        if ($result === ActionManager::TWO_FACTOR_CHALLENGE) {
            return Redirect::to('/auth?challenge=1');
        }
        Workspace::clear();
        return $this->afterLogin();
    }

    public function onChallenge()
    {
        ActionManager::instance()->twoFactorChallenge(post());
        Workspace::clear();
        return $this->afterLogin();
    }

    private function afterLogin()
    {
        $token = Session::pull('vmod.invitation');
        return Redirect::to(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? '/invitations/' . $token : '/workspaces');
    }

    public function onLogout()
    {
        $invitationToken = $this->property('view') === 'invitation' ? (string) $this->param('token') : '';
        if (Auth::getUser()) {
            ActionManager::instance()->logout();
        } else {
            // RainLab can reject a cached user after persist-code revocation; Laravel logout assumes a user.
            \Auth::logoutQuietly();
            Session::invalidate();
            Session::regenerateToken();
        }
        return Redirect::to(preg_match('/^[a-f0-9]{64}$/D', $invitationToken) ? '/invitations/' . $invitationToken : '/auth');
    }

    public function onRecover()
    {
        (new IdentityService)->recover(post());
        Flash::success('Recovery requested. If your account is eligible, check your email for instructions.');
    }

    public function onReset()
    {
        (new IdentityService)->reset(post());
        Flash::success('Password updated. Sign in with your new password.');
        return Redirect::to('/auth');
    }

    public function onAccept()
    {
        $user = (new InvitationService)->accept((string) $this->param('token'), post());
        \Auth::login($user);
        Session::regenerate();
        Session::forget('vmod.invitation');
        Workspace::clear();
        Flash::success('Invitation accepted. Choose your workspace.');
        return Redirect::to('/workspaces');
    }

    public function onProfile()
    {
        $user = Auth::getUser();
        abort_unless($user, 403);
        $data = Validator::make(post(), ['first_name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255'])->validate();
        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'] ?? null;
        $user->save();
        Flash::success('Profile saved.');
        return Redirect::to('/account');
    }

    public function onPassword()
    {
        ActionManager::instance()->changePassword(post());
        Flash::success('Password updated.');
        return Redirect::to('/account');
    }
}
