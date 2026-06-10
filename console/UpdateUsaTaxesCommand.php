<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Classes\Taxes\UsaTaxImporter;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxImportStaging;

class UpdateUsaTaxesCommand extends Command
{
    protected $signature = 'posmall:usa-taxes:update {--states=* : USA state codes to update}';

    protected $description = 'Stage and import normalized USA tax records';

    public function handle(UsaTaxImporter $importer): int
    {
        $states = $this->option('states') ?: $this->activeStates();

        if (!$states) {
            $this->info('No USA taxes are opted in for automatic source updates.');

            return 0;
        }

        $batchId = $importer->stageStates($states);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)
            ->ready()
            ->pluck('id')
            ->all();

        $count = $importer->importStaging($ids, true);

        $this->info(sprintf('USA tax update batch %s imported %d records.', $batchId, $count));

        return 0;
    }

    protected function activeStates(): array
    {
        $states = Tax::where('is_enabled', true)
            ->where('usa_auto_update_enabled', true)
            ->whereNotNull('state_code')
            ->where(function ($query) {
                $query->whereNotNull('source_url')
                    ->orWhereNotNull('boundary_source_url')
                    ->orWhere(function ($query) {
                        $query->whereNotNull('source_type')
                            ->where('source_type', '!=', 'MANUAL');
                    })
                    ->orWhereNotNull('source_name')
                    ->orWhereNotNull('parser_name')
                    ->orWhereNotNull('source_hash');
            })
            ->pluck('state_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $states;
    }
}
