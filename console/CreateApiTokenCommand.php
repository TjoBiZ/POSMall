<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Models\ApiToken;

class CreateApiTokenCommand extends Command
{
    protected $signature = 'posmall:api-token:create
        {name : Human-readable token name}
        {--scope=* : Token scope. Repeat for multiple scopes. Use * only for trusted local testing.}
        {--rate-limit= : Optional per-token requests per minute}
        {--origin=* : Optional allowed browser origin. Repeat for multiple origins.}';

    protected $description = 'Create a POSMall API token and print the plain token once.';

    public function handle(): int
    {
        $plainToken = ApiToken::generatePlainToken();
        $scopes = $this->option('scope') ?: ['catalog:read'];
        $origins = $this->option('origin') ?: [];

        $token = new ApiToken();
        $token->name = (string)$this->argument('name');
        $token->scopes = array_values(array_unique(array_map('strval', $scopes)));
        $token->allowed_origins = array_values(array_unique(array_filter(array_map('strval', $origins))));
        $token->rate_limit_per_minute = $this->option('rate-limit') !== null
            ? max(1, (int)$this->option('rate-limit'))
            : null;
        $token->setPlainToken($plainToken);
        $token->save();

        $this->warn('Store this token now. POSMall stores only its SHA-256 hash and cannot show it again.');
        $this->line($plainToken);

        return 0;
    }
}
