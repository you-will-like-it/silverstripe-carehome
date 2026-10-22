<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Patient\BedAssignment;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Bed occupancy history and the "free beds" option list.
 */
class BedAssignmentService
{
    use Injectable;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Free beds plus the bed currently held by the edited resident.
     *
     * @return array<int,string>
     */
    public function bedOptions(?int $currentBedId): array
    {
        $taken = array_map('intval', array_filter(
            Patient::get()->exclude('BedLock', null)->column('BedLock')
        ));

        $options = [];
        foreach (FacilityBed::get() as $bed) {
            $id = (int) $bed->ID;
            if ($id === $currentBedId || !in_array($id, $taken, true)) {
                $options[$id] = $bed->getFullTitle();
            }
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * Close the open assignment and open a new one if the resident got a (different) bed.
     */
    public function recordChange(Patient $patient, int $newBedId): void
    {
        $now = $this->clock->toDbDatetime($this->clock->now());

        $open = BedAssignment::get()->filter([
            'PatientID' => (int) $patient->ID,
            'EndedAt' => null,
        ]);
        foreach ($open as $assignment) {
            if ((int) $assignment->BedID === $newBedId) {
                return;
            }
            $assignment->EndedAt = $now;
            $assignment->write();
        }

        if ($newBedId > 0) {
            $assignment = BedAssignment::create();
            $assignment->PatientID = (int) $patient->ID;
            $assignment->BedID = $newBedId;
            $assignment->StartedAt = $now;
            $assignment->write();
        }
    }
}
