<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Patient;

use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Bed occupancy history (written by BedAssignmentService only).
 *
 * @property string $StartedAt
 * @property string|null $EndedAt
 * @property int $PatientID
 * @property int $BedID
 * @method Patient Patient()
 * @method FacilityBed Bed()
 */
class BedAssignment extends DataObject
{
    private static string $table_name = 'CareHome_BedAssignment';

    private static array $db = [
        'StartedAt' => 'Datetime',
        'EndedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'Bed' => FacilityBed::class,
    ];

    private static array $indexes = [
        'PatientOpen' => ['type' => 'index', 'columns' => ['PatientID', 'EndedAt']],
    ];

    private static string $default_sort = '"StartedAt" DESC';

    private static array $summary_fields = [
        'Bed.FullTitle',
        'StartedAt.Nice',
        'EndedAt.Nice',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Smještaj u krevet');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Historija kreveta');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Bed.FullTitle' => _t(__CLASS__ . '.BED', 'Krevet'),
            'StartedAt.Nice' => _t(__CLASS__ . '.STARTED', 'Od'),
            'EndedAt.Nice' => _t(__CLASS__ . '.ENDED', 'Do'),
            'StartedAt' => _t(__CLASS__ . '.STARTED', 'Od'),
            'EndedAt' => _t(__CLASS__ . '.ENDED', 'Do'),
        ]);
    }

    public function canView($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
