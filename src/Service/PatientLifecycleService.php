<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareHome\Model\Kitchen\PatientMealSelection;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Side effects of resident admission, bed change, absence and exit.
 */
class PatientLifecycleService
{
    use Injectable;

    public function __construct(
        private readonly BedAssignmentService $beds,
        private readonly TaskGenerator $tasks,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,array{before:mixed,after:mixed}> $changes
     */
    public function afterPatientWrite(Patient $patient, array $changes, bool $isNew): void
    {
        if ($isNew || isset($changes['BedID'])) {
            $this->beds->recordChange($patient, (int) $patient->BedID);
        }

        $careStateChanged = $isNew || isset($changes['Status']) || isset($changes['IsTemporarilyAbsent']);
        if (!$careStateChanged) {
            return;
        }

        // removes future open tasks when unavailable, (re)creates them when available again
        $this->tasks->syncPatient($patient);

        if (!$patient->isAvailableForCare()) {
            $this->purgeFutureMeals($patient);
        }
    }

    /**
     * Absent/inactive residents get no meals from tomorrow on (today's kitchen order is final).
     */
    public function purgeFutureMeals(Patient $patient): int
    {
        $count = 0;
        $selections = PatientMealSelection::get()->filter([
            'PatientID' => (int) $patient->ID,
            'SelectedDate:GreaterThan' => $this->clock->today()->format('Y-m-d'),
        ]);
        foreach ($selections as $selection) {
            $selection->delete();
            $count++;
        }

        return $count;
    }
}
