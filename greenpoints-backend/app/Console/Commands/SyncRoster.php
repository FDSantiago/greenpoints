<?php

namespace App\Console\Commands;

use App\Integrations\SchoolRegistry\Exceptions\RegistryException;
use App\Services\RosterSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncRoster extends Command
{
    protected $signature = 'greenpoints:sync-roster {section? : One class group / registry section, e.g. IT3A (defaults to all class groups)}';

    protected $description = 'Compare class groups with the School Registry rosters and update names/courses (never deletes users)';

    public function handle(RosterSyncService $service): int
    {
        $section = $this->argument('section');

        if ($section !== null && ! preg_match('/^[A-Za-z0-9-]{1,20}$/', $section)) {
            $this->error('Section must be 1-20 letters, digits or dashes, e.g. IT3A.');

            return self::FAILURE;
        }

        try {
            $report = $service->sync($section !== null ? Str::upper($section) : null);
        } catch (RegistryException $e) {
            $this->error('School Registry unavailable: '.$e->getMessage()." (request id {$e->requestId})");

            return self::FAILURE;
        }

        if ($report['sections'] === []) {
            $this->warn($section !== null ? "No class group named {$section}." : 'There are no class groups to sync.');

            return $section !== null ? self::FAILURE : self::SUCCESS;
        }

        $columns = ['section', 'registry_students', 'matched', 'updated', 'missing_in_registry', 'without_student_id'];
        $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($columns)), $report['sections']);
        $rows[] = ['section' => 'TOTAL'] + $report['totals'];

        $this->table($columns, $rows);

        return self::SUCCESS;
    }
}
