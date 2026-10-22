<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Facility;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Join object Member <-> FacilityZone. A real DataObject so that granting/revoking
 * zone access shows up in the audit log.
 *
 * @property int $MemberID
 * @property int $ZoneID
 * @method Member Member()
 * @method FacilityZone Zone()
 */
class MemberZoneAssignment extends DataObject
{
    private static string $table_name = 'CareHome_MemberZoneAssignment';

    private static array $has_one = [
        'Member' => Member::class,
        'Zone' => FacilityZone::class,
    ];

    private static array $indexes = [
        'MemberZone' => ['type' => 'unique', 'columns' => ['MemberID', 'ZoneID']],
    ];

    public function getTitle(): string
    {
        return $this->Member()->getName() . ' → ' . $this->Zone()->getBreadcrumb();
    }

    public function canView($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canEdit($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canDelete($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }
}
