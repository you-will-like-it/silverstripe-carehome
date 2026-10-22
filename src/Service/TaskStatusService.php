<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\VitalParameterDefinition;
use YouWillLikeIT\CareHome\Model\Task\VitalReading;

/**
 * Portal-side task execution: complete (with vitals), skip (reason required), undo.
 * All messages thrown as RuntimeException are user-facing.
 */
class TaskStatusService
{
    use Injectable;
    use Configurable;

    /** A worker may revert their own entry within this many minutes; afterwards only admins (CMS). */
    private static int $undo_window_minutes = 30;

    /** Tasks from yesterday may still be recorded (night shift crossing midnight). */
    private static int $grace_days_back = 1;

    public function __construct(
        private readonly Clock $clock,
        private readonly ZoneScope $scope,
    ) {
    }

    /**
     * @param array<int|string,string|null> $vitalInputs definitionID => raw input
     */
    public function complete(DailyTask $task, Member $worker, ?string $note, array $vitalInputs = []): void
    {
        $this->assertActionable($task, $worker);

        $readings = [];
        foreach ($task->VitalDefinitions() as $definition) {
            $raw = trim((string) ($vitalInputs[$definition->ID] ?? ''));
            if ($raw === '') {
                throw new RuntimeException(_t(
                    __CLASS__ . '.VITAL_REQUIRED',
                    'Unesite vrijednost: {name}.',
                    ['name' => $definition->Title]
                ));
            }
            $readings[] = [$definition, $this->parseValue($definition, $raw)];
        }

        $now = $this->clock->toDbDatetime($this->clock->now());

        DB::get_conn()->withTransaction(function () use ($task, $worker, $note, $readings, $now): void {
            $this->lockOpen($task);
            foreach ($readings as [$definition, $value]) {
                $reading = VitalReading::create();
                $reading->TaskID = (int) $task->ID;
                $reading->PatientID = (int) $task->PatientID;
                $reading->DefinitionID = (int) $definition->ID;
                $reading->DefinitionTitle = (string) $definition->Title;
                $reading->Unit = (string) $definition->Unit;
                $reading->Value = $value;
                $reading->MeasuredAt = $now;
                $reading->IsAbnormal = $definition->isAbnormal($value);
                $reading->write();
            }

            $task->Status = DailyTask::STATUS_DONE;
            $task->ExecutionNote = $this->cleanText($note, 2000);
            $task->SkipReason = null;
            $task->CompletedAt = $now;
            $task->CompletedByID = (int) $worker->ID;
            $task->write();
        }, null, false, false);
    }

    public function skip(DailyTask $task, Member $worker, ?string $reason): void
    {
        $this->assertActionable($task, $worker);

        $reason = $this->cleanText($reason, 2000);
        if ($reason === null || mb_strlen($reason) < 3) {
            throw new RuntimeException(_t(__CLASS__ . '.REASON_REQUIRED', 'Razlog preskakanja je obavezan.'));
        }

        DB::get_conn()->withTransaction(function () use ($task, $worker, $reason): void {
            $this->lockOpen($task);
            $task->Status = DailyTask::STATUS_SKIPPED;
            $task->SkipReason = $reason;
            $task->CompletedAt = $this->clock->toDbDatetime($this->clock->now());
            $task->CompletedByID = (int) $worker->ID;
            $task->write();
        }, null, false, false);
    }

    public function undo(DailyTask $task, Member $worker): void
    {
        if (!$this->canUndo($task, $worker)) {
            throw new RuntimeException(_t(
                __CLASS__ . '.UNDO_EXPIRED',
                'Poništavanje više nije moguće. Ispravku radi administrator.'
            ));
        }

        $task->Status = DailyTask::STATUS_OPEN;
        $task->write(); // DailyTask clears completion data and removes readings
    }

    public function canUndo(DailyTask $task, Member $worker): bool
    {
        if ($task->isOpen() || (int) $task->CompletedByID !== (int) $worker->ID || !$task->CompletedAt) {
            return false;
        }
        if (!$this->scope->canAccessPatient($worker, $task->Patient())) {
            return false;
        }
        $completed = $this->clock->combine(substr((string) $task->CompletedAt, 0, 10), substr((string) $task->CompletedAt, 11, 8));
        $limit = $completed->modify('+' . (int) static::config()->get('undo_window_minutes') . ' minutes');

        return $this->clock->now() <= $limit;
    }

    public function undoMinutesLeft(DailyTask $task, Member $worker): int
    {
        if (!$this->canUndo($task, $worker)) {
            return 0;
        }
        $completed = $this->clock->combine(substr((string) $task->CompletedAt, 0, 10), substr((string) $task->CompletedAt, 11, 8));
        $limit = $completed->modify('+' . (int) static::config()->get('undo_window_minutes') . ' minutes');

        return max(1, (int) ceil(($limit->getTimestamp() - $this->clock->now()->getTimestamp()) / 60));
    }

    /**
     * Row lock + re-check inside the transaction: two devices recording the same task
     * at the same moment cannot both succeed (MySQL/MariaDB, PostgreSQL).
     */
    private function lockOpen(DailyTask $task): void
    {
        $status = DB::prepared_query(
            'SELECT "Status" FROM "CareHome_DailyTask" WHERE "ID" = ? FOR UPDATE',
            [(int) $task->ID]
        )->value();
        if ((int) $status !== DailyTask::STATUS_OPEN) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_OPEN', 'Zadatak je već evidentiran.'));
        }
    }

    private function assertActionable(DailyTask $task, Member $worker): void
    {
        if (!$task->isOpen()) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_OPEN', 'Zadatak je već evidentiran.'));
        }
        $patient = $task->Patient();
        if (!$patient->exists() || !$this->scope->canAccessPatient($worker, $patient)) {
            throw new RuntimeException(_t(__CLASS__ . '.NO_ACCESS', 'Nemate pristup ovom štićeniku.'));
        }
        if (!$patient->isAvailableForCare()) {
            throw new RuntimeException(_t(__CLASS__ . '.ABSENT', 'Štićenik je privremeno odsutan.'));
        }

        $today = $this->clock->today();
        $earliest = $today->modify('-' . (int) static::config()->get('grace_days_back') . ' days')->format('Y-m-d');
        if ($task->DueDate > $today->format('Y-m-d') || $task->DueDate < $earliest) {
            throw new RuntimeException(_t(__CLASS__ . '.WRONG_DAY', 'Zadatak nije za današnji dan.'));
        }
    }

    private function parseValue(VitalParameterDefinition $definition, string $raw): float
    {
        $normalised = str_replace([' ', ','], ['', '.'], $raw);
        $pattern = $definition->ValueType === VitalParameterDefinition::TYPE_INTEGER
            ? '/^-?\d+$/'
            : '/^-?\d+(\.\d{1,2})?$/';
        if (!preg_match($pattern, $normalised)) {
            throw new RuntimeException(_t(
                __CLASS__ . '.VITAL_FORMAT',
                'Neispravan unos za {name}.',
                ['name' => $definition->Title]
            ));
        }
        $value = (float) $normalised;
        if (!$definition->isPlausible($value)) {
            throw new RuntimeException(_t(
                __CLASS__ . '.VITAL_RANGE',
                '{name}: vrijednost {value} nije moguća (dozvoljeno {min}–{max}). Provjerite unos.',
                [
                    'name' => $definition->Title,
                    'value' => $raw,
                    'min' => $definition->formatValue((float) $definition->MinValue),
                    'max' => $definition->formatValue((float) $definition->MaxValue),
                ]
            ));
        }

        return $value;
    }

    private function cleanText(?string $text, int $max): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
