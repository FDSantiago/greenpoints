<?php

namespace App\Console\Commands;

use App\Services\ImpactReportService;
use Illuminate\Console\Command;

class GenerateImpactReports extends Command
{
    protected $signature = 'greenpoints:generate-impact-reports {month? : Month as YYYY-MM (defaults to last month)}';

    protected $description = 'Store the monthly impact report (kg diverted, points) for every class/zone';

    public function handle(ImpactReportService $service): int
    {
        $arg = $this->argument('month');

        if ($arg !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $arg)) {
            $this->error('Month must look like 2026-10.');

            return self::FAILURE;
        }

        $month = $arg !== null ? $service->parseMonth($arg) : now()->subMonthNoOverflow()->startOfMonth();
        $rows = $service->generate($month);

        $this->info("Stored {$rows->count()} report row(s) for {$month->format('Y-m')}.");

        return self::SUCCESS;
    }
}
