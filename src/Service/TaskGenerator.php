<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DB;
use Throwable;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\IndividualTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\TaskTemplate;

/**
 * Synchronises DailyTask rows with templates for a rolling window [now, today + horizon].
 *
 * Rules:
 * - Only OPEN tasks due at/after "now" are ever created, updated or deleted.
 * - Done/skipped tasks and open tasks already in the past are never touched (history, overdue items).
 * - Missing tasks are created; obsolete ones (template removed/changed/deactivated, patient
 *   unassigned/absent/inactive) are deleted; changed prescriptions update the snapshot.
 * - Idempotent; a UNIQUE index (Patient, Template, Date, Time) makes concurrent runs safe.
 */
class TaskGenerator
{
    use Injectable;
    use Configurable;

    /** Days ahead (today + N). */
    private static int $horizon_days = 7;

    private const array SNAPSHOT_FIELDS = [
        'Title', 'Category', 'Medication', 'Dose', 'Route', 'Instructions', 'VitalParameterIDs',
    ];

    /** Guards against re-entrant syncs triggered from inside a sync. */
    private bool $running = false;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @return array{patients:int, created:int, updated:int, deleted:int}
     */
    public function syncAll(): array
    {
        $totals = ['patients' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];

        // all active residents + inactive ones that may still have future open tasks
        $ids = array_unique(array_merge(
            Patient::get()->filter('Status', Patient::STATUS_ACTIVE)->column('ID'),
            DailyTask::get()
                ->filter(['Status' => DailyTask::STATUS_OPEN, 'DueDate:GreaterThanOrEqual' => $this->clock->today()->format('Y-m-d')])
                ->columnUnique('PatientID')
        ));

        foreach ($ids as $id) {
            $patient = Patient::get()->byID((int) $id);
            if (!$patient) {
                continue;
            }
            $stats = $this->syncPatient($patient);
            $totals['patients']++;
            $totals['created'] += $stats['created'];
            $totals['updated'] += $stats['updated'];
            $totals['deleted'] += $stats['deleted'];
        }

        return $totals;
    }

    /**
     * @param list<int> $patientIds
     */
    public function syncPatients(array $patientIds): void
    {
        if ($this->running) {
            return;
        }
        foreach (array_unique(array_filter(array_map('intval', $patientIds))) as $id) {
            $patient = Patient::get()->byID($id);
            if ($patient) {
                $this->syncPatient($patient);
            }
        }
    }

    /**
     * @return array{created:int, updated:int, deleted:int}
     */
    public function syncPatient(Patient $patient): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        if ($this->running) {
            return $stats;
        }
        $this->running = true;

        try {
            $now = $this->clock->now();
            $today = $this->clock->today();
            $end = $today->modify('+' . (int) static::config()->get('horizon_days') . ' days');

            $desired = $patient->isAvailableForCare() ? $this->desiredTasks($patient, $now, $today, $end) : [];

            // generated rows are derived data: the triggering change (template, assignment, status)
            // is what the audit log records, not hundreds of open task rows
            $work = function () use ($patient, $now, $today, $end, &$desired, &$stats): void {
                $existing = DailyTask::get()->filter([
                    'PatientID' => (int) $patient->ID,
                    'DueDate:GreaterThanOrEqual' => $today->format('Y-m-d'),
                    'DueDate:LessThanOrEqual' => $end->format('Y-m-d'),
                ]);

                foreach ($existing as $task) {
                    $key = self::key((int) $task->TemplateID, (string) $task->DueDate, (string) $task->DueTime);

                    // history and overdue items stay as they are
                    if (!$task->isOpen() || $task->getDueAt() < $now) {
                        unset($desired[$key]);
                        continue;
                    }

                    if (!isset($desired[$key])) {
                        $task->delete();
                        $stats['deleted']++;
                        continue;
                    }

                    if ($this->applySnapshot($task, $desired[$key]['template']->snapshot())) {
                        $task->write();
                        $stats['updated']++;
                    }
                    unset($desired[$key]);
                }

                foreach ($desired as $item) {
                    $task = DailyTask::create();
                    $task->PatientID = (int) $patient->ID;
                    $task->TemplateID = (int) $item['template']->ID;
                    $task->DueDate = $item['date'];
                    $task->DueTime = $item['time'];
                    $task->Status = DailyTask::STATUS_OPEN;
                    $this->applySnapshot($task, $item['template']->snapshot());
                    try {
                        $task->write();
                        $stats['created']++;
                    } catch (ValidationException) {
                        // unique index hit: a concurrent run created it already
                    }
                }
            };
            AuditContext::singleton()->suppress(
                static fn () => DB::get_conn()->withTransaction($work, null, false, false)
            );
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                sprintf('CareHome task sync failed for patient #%d: %s', $patient->ID, $e->getMessage()),
                ['exception' => $e]
            );
            throw $e;
        } finally {
            $this->running = false;
        }

        return $stats;
    }

    /**
     * @return array<string,array{template:TaskTemplate,date:string,time:string}>
     */
    private function desiredTasks(Patient $patient, DateTimeImmutable $now, DateTimeImmutable $today, DateTimeImmutable $end): array
    {
        /** @var list<TaskTemplate> $templates */
        $templates = array_merge(
            iterator_to_array($patient->AssignedGlobalTasks()->filter('IsActive', true), false),
            iterator_to_array(IndividualTaskTemplate::get()->filter([
                'PatientID' => (int) $patient->ID,
                'IsActive' => true,
            ]), false)
        );

        $desired = [];
        for ($day = $today; $day <= $end; $day = $day->modify('+1 day')) {
            $ymd = $day->format('Y-m-d');
            foreach ($templates as $template) {
                if (!$template->ScheduledTime || !$template->occursOn($day)) {
                    continue;
                }
                $time = self::normaliseTime((string) $template->ScheduledTime);
                if ($this->clock->combine($ymd, $time) < $now) {
                    continue;
                }
                $desired[self::key((int) $template->ID, $ymd, $time)] = [
                    'template' => $template,
                    'date' => $ymd,
                    'time' => $time,
                ];
            }
        }

        return $desired;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return bool true if anything changed
     */
    private function applySnapshot(DailyTask $task, array $snapshot): bool
    {
        $changed = false;
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $snapshot[$field] ?? null;
            $value = $value === '' ? null : $value;
            $current = $task->getField($field);
            $current = $current === '' ? null : $current;
            if ($current !== $value) {
                $task->setField($field, $value);
                $changed = true;
            }
        }

        return $changed;
    }

    private static function key(int $templateId, string $date, string $time): string
    {
        return $templateId . '|' . $date . '|' . self::normaliseTime($time);
    }

    private static function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : substr($time, 0, 8);
    }
}
