<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Resolves which zones / beds / residents a worker may see.
 * Assigning a parent zone (building, wing) includes all of its sub-zones.
 * CareHome admins see everything.
 */
class ZoneScope
{
    use Injectable;

    /** @var array<int,int>|null child => parent map, cached per request */
    private ?array $parentMap = null;

    public function reset(): void
    {
        $this->parentMap = null;
    }

    /**
     * Given zone IDs plus all their descendants.
     *
     * @param list<int> $zoneIds
     * @return list<int>
     */
    public function descendantIds(array $zoneIds): array
    {
        $childrenOf = [];
        foreach ($this->parentMap() as $child => $parent) {
            $childrenOf[$parent][] = $child;
        }

        $result = [];
        $queue = array_values(array_filter(array_map('intval', $zoneIds)));
        while ($queue) {
            $id = array_shift($queue);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = $id;
            foreach ($childrenOf[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return array_values($result);
    }

    /**
     * @return list<int>|null null = unrestricted (admin)
     */
    public function zoneIdsFor(Member $worker): ?array
    {
        if (CareHomePermissions::check(CareHomePermissions::ADMIN, $worker)) {
            return null;
        }
        $assigned = array_map('intval', $worker->AssignedZones()->column('ID'));

        return $this->descendantIds($assigned);
    }

    /**
     * Top-level zones assigned to the worker (for headings / handover).
     *
     * @return DataList<FacilityZone>
     */
    public function assignedZones(Member $worker): DataList
    {
        if (CareHomePermissions::check(CareHomePermissions::ADMIN, $worker)) {
            return FacilityZone::get()->filter('ParentID', 0);
        }

        $ids = array_map('intval', $worker->AssignedZones()->column('ID'));

        return FacilityZone::get()->filter('ID', $ids ?: [0]);
    }

    public function canAccessZone(Member $worker, int $zoneId): bool
    {
        $ids = $this->zoneIdsFor($worker);

        return $ids === null || in_array($zoneId, $ids, true);
    }

    public function canAccessBed(Member $worker, FacilityBed $bed): bool
    {
        return $this->canAccessZone($worker, (int) $bed->Room()->ZoneID);
    }

    public function canAccessPatient(Member $worker, Patient $patient): bool
    {
        if (!$patient->isActive() || !$patient->BedID) {
            return false;
        }

        return $this->canAccessBed($worker, $patient->Bed());
    }

    /**
     * Active residents (incl. temporarily absent) in the worker's zones.
     *
     * @return DataList<Patient>
     */
    public function patientsFor(Member $worker): DataList
    {
        $list = Patient::get()
            ->filter('Status', Patient::STATUS_ACTIVE)
            ->exclude('BedID', 0);
        $ids = $this->zoneIdsFor($worker);

        return $ids === null ? $list : $list->filter('Bed.Room.ZoneID', $ids ?: [0]);
    }

    /** @return array<int,int> */
    private function parentMap(): array
    {
        if ($this->parentMap === null) {
            $this->parentMap = [];
            $rows = SQLSelect::create(['"ID"', '"ParentID"'], '"CareHome_FacilityZone"')->execute();
            foreach ($rows as $row) {
                $this->parentMap[(int) $row['ID']] = (int) $row['ParentID'];
            }
        }

        return $this->parentMap;
    }
}
