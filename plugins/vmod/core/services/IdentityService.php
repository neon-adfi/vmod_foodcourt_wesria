<?php namespace Vmod\Core\Services;

use DB;

class IdentityService
{
    public function recover(array $input): void
    {
        $data = \Validator::make($input, ['email' => 'required|email|max:255'])->validate();
        try {
            \RainLab\User\Classes\ActionManager::instance()->recoverPassword($data, [
                'resetUrl' => rtrim(config('app.url'), '/') . '/auth/reset',
            ]);
        } catch (\October\Rain\Exception\ValidationException $exception) {
            // A valid email gets the same response for sent, unknown and throttled outcomes.
        } catch (\Throwable $exception) {
            // Transport/configuration failures must not reveal whether the email is registered.
            // Omit raw messages/trace arguments: mail exceptions may contain credentials or recovery tokens.
            \Log::error('[IdentityService] Password recovery could not be delivered.', ['exception' => get_class($exception)]);
        }
    }

    public function reset(array $input): void
    {
        \RainLab\User\Classes\ActionManager::instance()->resetPassword($input);
        \Vmod\Core\Classes\Workspace::clear();
    }

    public static function withAccountCreationLock(callable $callback)
    {
        // Native users.email has no unique index and its collation folds accents as well as case.
        // Serialize low-volume Core account creation; other writers need this lock or an approved unique index.
        $name = 'vmod-core-account-create';
        abort_unless((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name])->acquired === 1, 409, 'Please retry this request.');
        try {
            return $callback();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
