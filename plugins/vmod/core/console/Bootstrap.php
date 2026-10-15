<?php namespace Vmod\Core\Console;

use Illuminate\Console\Command;
use Vmod\Core\Services\BootstrapService;

class Bootstrap extends Command
{
    protected $signature = 'vmod:bootstrap';
    protected $description = 'Create a company, branch and new frontend owner in the owned local/test database';

    public function handle(): int
    {
        $service = new BootstrapService;
        $service->assertOwnedEnvironment();
        if (!$this->input->isInteractive()) {
            $this->error('Bootstrap requires interactive secret prompts.');
            return 1;
        }
        $input = [];
        foreach (['company_code', 'company_name', 'branch_name', 'first_name', 'email'] as $field) {
            $input[$field] = $this->ask(ucwords(str_replace('_', ' ', $field)));
        }
        $input['password'] = $this->secret('Password');
        $input['password_confirmation'] = $this->secret('Confirm password');
        $created = $service->create($input);
        $this->info('Company ' . $created['company']->code . ' created. The owner can sign in at /auth.');
        return 0;
    }
}
