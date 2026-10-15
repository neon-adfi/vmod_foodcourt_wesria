<?php

// Independent PHP processes and real MySQL locks. This never clears or drops database data.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
foreach (['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_PATH' => '/tmp/wesria-core-cache', 'STORAGE_PATH' => '/tmp/wesria-core-storage'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
\System\Classes\PluginManager::instance()->registerAll(true);
\System\Classes\PluginManager::instance()->bootAll(true);
$bootstrap = new \Vmod\Core\Services\BootstrapService;
$bootstrap->assertOwnedEnvironment();

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function acting(int $userId, int $companyId): void
{
    Auth::logoutQuietly();
    Auth::login(\Vmod\Core\Models\User::findOrFail($userId));
    \Vmod\Core\Classes\Workspace::clear();
    \Vmod\Core\Classes\Workspace::switchTo($companyId);
    Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
}

function company(): array
{
    $nonce = bin2hex(random_bytes(8));
    return (new \Vmod\Core\Services\BootstrapService)->create([
        'company_code' => 'race-' . $nonce, 'company_name' => 'Concurrency ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
        'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
    ]);
}

function race(array $jobs): array
{
    $barrier = sys_get_temp_dir() . '/wesria-core-race-' . bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $processes = [];
    try {
        foreach ($jobs as $index => $job) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            check(is_resource($process), 'Cannot start independent worker.');
            fwrite($pipes[0], json_encode($job + ['barrier' => $barrier, 'index' => $index], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier . '/ready-*')) !== count($jobs)) {
            check(microtime(true) < $deadline, 'Workers did not reach the synchronization barrier.');
            usleep(10000);
        }
        touch($barrier . '/go');
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            check($exit === 0 && $error === '', 'Worker failed; no sensitive worker output is printed.');
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($processes as [$process]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach (glob($barrier . '/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (isset($job['user_id'])) {
            acting($job['user_id'], $job['company_id']);
        }
        touch($job['barrier'] . '/ready-' . $job['index']);
        $deadline = microtime(true) + 20;
        while (!is_file($job['barrier'] . '/go')) {
            check(microtime(true) < $deadline, 'Barrier timed out.');
            usleep(10000);
        }
        $admin = new \Vmod\Core\Services\AdministrationService;
        $invitations = new \Vmod\Core\Services\InvitationService;
        switch ($job['operation']) {
            case 'demote': $admin->saveMember($job['input']); break;
            case 'branch': $admin->saveBranch($job['input']); break;
            case 'issue': $invitations->issue($job['input']); break;
            case 'accept': $invitations->accept($job['token'], [
                'first_name' => 'Concurrent member', 'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
            ]); break;
            default: throw new RuntimeException('Unknown test operation.');
        }
        echo json_encode(['status' => 200]);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode()]);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception));
        exit(1);
    }
    exit(0);
}

try {
    check($kernel->call('october:migrate', ['--no-interaction' => true]) === 0, 'Migration failed.');
    $one = company();
    $two = company();
    $otherAdmin = \Vmod\Core\Models\Membership::create([
        'company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $one['membership']->role_id,
    ]);
    $otherAdmin->branches()->attach($one['branch']->id);
    $base = ['company_id' => $one['company']->id, 'user_id' => $one['owner']->id];
    $jobs = [];
    foreach ([$one['membership'], $otherAdmin] as $member) {
        $jobs[] = ['operation' => 'demote', 'company_id' => $one['company']->id, 'user_id' => $member->user_id, 'input' => [
            'id' => $member->id, 'role_id' => $member->role_id, 'branch_ids' => [$one['branch']->id], 'is_active' => false,
        ]];
    }
    $statuses = array_column(race($jobs), 'status');
    sort($statuses);
    check($statuses === [200, 409], 'Competing last-admin changes must produce one rollback.');
    $remaining = \Vmod\Core\Models\Membership::where('company_id', $one['company']->id)->where('is_active', true)->get();
    check($remaining->count() === 1 && $remaining->first()->isFullAdministrator(), 'One full administrator must remain.');
    $base['user_id'] = $remaining->first()->user_id;
    $statuses = array_column(race([
        $base + ['operation' => 'branch', 'input' => ['name' => 'Concurrent North']],
        $base + ['operation' => 'branch', 'input' => ['name' => 'Concurrent South']],
    ]), 'status');
    check($statuses === [200, 200] && $remaining->first()->fresh()->isFullAdministrator(), 'Concurrent branches must preserve full grants.');

    $email = bin2hex(random_bytes(8)) . '@example.invalid';
    $input = ['email' => $email, 'role_id' => $one['membership']->role_id, 'branch_ids' => [$one['branch']->id]];
    $statuses = array_column(race([$base + ['operation' => 'issue', 'input' => $input], $base + ['operation' => 'issue', 'input' => $input]]), 'status');
    check($statuses === [200, 200] && \Vmod\Core\Models\Invitation::pending()->where('email', $email)->count() === 1, 'Concurrent issue must retain one pending invitation.');
    acting($base['user_id'], $base['company_id']);
    $issued = (new \Vmod\Core\Services\InvitationService)->issue($input);
    $statuses = array_column(race([['operation' => 'accept', 'token' => $issued['token']], ['operation' => 'accept', 'token' => $issued['token']]]), 'status');
    sort($statuses);
    check($statuses === [200, 409], 'Invitation replay race must have one winner.');
    check(\Vmod\Core\Models\User::where('email', $email)->count() === 1, 'Replay race created duplicate accounts.');
    $user = \Vmod\Core\Models\User::where('email', $email)->firstOrFail();
    check(\Vmod\Core\Models\Membership::where('user_id', $user->id)->count() === 1, 'Replay race created duplicate memberships.');

    // MySQL's native email collation equates accented local parts; locks must serialize that equality too.
    $email = 'jose-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $equivalentEmail = str_replace('jose-', 'josé-', $email);
    $first = (new \Vmod\Core\Services\InvitationService)->issue(array_replace($input, ['email' => $email]));
    acting($two['owner']->id, $two['company']->id);
    $second = (new \Vmod\Core\Services\InvitationService)->issue(['email' => $equivalentEmail, 'role_id' => $two['membership']->role_id, 'branch_ids' => []]);
    $statuses = array_column(race([['operation' => 'accept', 'token' => $first['token']], ['operation' => 'accept', 'token' => $second['token']]]), 'status');
    sort($statuses);
    check($statuses === [200, 403], 'Cross-company email creation must require login after the first winner.');
    check(\Vmod\Core\Models\User::where('email', $email)->count() === 1, 'Cross-company race created duplicate native users.');
    echo "Core MySQL concurrency: 5 synchronized independent-process scenarios passed (last admin, branches, resend, acceptance, cross-company email).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
