<?php

namespace App\Services;

use App\Integrations\SchoolRegistry\RegistryStudent;
use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Support\Collection;

class RosterSyncService
{
    public function __construct(private SchoolRegistryClient $registry) {}

    /**
     * Pull the School Registry roster for each class group's section and compare it with our users.
     *
     * Per section, for users who have a student_id:
     * - matched:             found in the registry roster (includes `updated`)
     * - updated:             matched, and their name or course changed to the registry's value
     * - missing_in_registry: not in the registry roster for this section (left as they are, never deleted)
     * Users without a student_id (created before the integration) are only counted.
     *
     * Running it twice in a row gives the same matched/missing counts and `updated: 0` the second time.
     * A RegistryException (registry down) stops the sync; updates already written are safe to keep.
     *
     * @return array{sections: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function sync(?string $section = null): array
    {
        $groups = ClassGroup::where('type', 'class')
            ->when($section !== null, fn ($query) => $query->where('name', $section))
            ->orderBy('name')
            ->get();

        $sections = $groups->map(fn (ClassGroup $group) => $this->syncGroup($group))->values()->all();

        $totals = [];
        foreach (['registry_students', 'matched', 'updated', 'missing_in_registry', 'without_student_id'] as $key) {
            $totals[$key] = array_sum(array_column($sections, $key));
        }

        return ['sections' => $sections, 'totals' => $totals];
    }

    private function syncGroup(ClassGroup $group): array
    {
        /** @var Collection<string, RegistryStudent> $roster */
        $roster = collect($this->registry->sectionRoster($group->name)->students)->keyBy('studentId');
        $users = User::where('group_id', $group->id)->where('role', User::ROLE_STUDENT)->get();

        $report = [
            'section' => $group->name,
            'registry_students' => $roster->count(),
            'matched' => 0,
            'updated' => 0,
            'missing_in_registry' => 0,
            'without_student_id' => 0,
            'missing_student_ids' => [],
        ];

        foreach ($users as $user) {
            if ($user->student_id === null) {
                $report['without_student_id']++;

                continue;
            }

            $student = $roster->get($user->student_id);
            if ($student === null) {
                $report['missing_in_registry']++;
                $report['missing_student_ids'][] = $user->student_id;

                continue;
            }

            $report['matched']++;
            $user->forceFill(['name' => $student->fullName(), 'course' => $student->course]);
            if ($user->isDirty()) {
                $user->save();
                $report['updated']++;
            }
        }

        return $report;
    }
}
