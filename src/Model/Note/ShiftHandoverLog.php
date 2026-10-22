<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Note;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\ShiftResolver;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Shift handover for a zone. Critical notes of that shift window (zone incl. sub-zones)
 * are aggregated dynamically — nothing is copied.
 *
 * @property string $ShiftDate
 * @property string $ShiftType code from ShiftResolver.shifts
 * @property string $GeneralNotes
 * @property int $ZoneID
 * @property int $HandedOverByID
 * @property int $ReceivedByID
 * @method FacilityZone Zone()
 * @method Member HandedOverBy()
 * @method Member ReceivedBy()
 */
class ShiftHandoverLog extends DataObject
{
    private static string $table_name = 'CareHome_ShiftHandoverLog';

    private static array $db = [
        'ShiftDate' => 'Date',
        'ShiftType' => 'Varchar(30)',
        'GeneralNotes' => 'Text',
    ];

    private static array $has_one = [
        'Zone' => FacilityZone::class,
        'HandedOverBy' => Member::class,
        'ReceivedBy' => Member::class,
    ];

    private static array $indexes = [
        'ZoneShift' => ['type' => 'index', 'columns' => ['ZoneID', 'ShiftDate', 'ShiftType']],
    ];

    private static string $default_sort = '"ShiftDate" DESC, "Created" DESC';

    private static array $summary_fields = [
        'ShiftDate.Nice',
        'ShiftLabel',
        'Zone.Title',
        'HandedOverBy.Name',
        'ReceivedBy.Name',
        'CriticalNoteCount',
    ];

    private static array $casting = [
        'ShiftLabel' => 'Varchar',
        'CriticalNoteCount' => 'Int',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Primopredaja smjene');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Primopredaje smjena');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'ShiftDate' => _t(__CLASS__ . '.SHIFTDATE', 'Datum smjene'),
            'ShiftDate.Nice' => _t(__CLASS__ . '.SHIFTDATE', 'Datum smjene'),
            'ShiftType' => _t(__CLASS__ . '.SHIFTTYPE', 'Smjena'),
            'ShiftLabel' => _t(__CLASS__ . '.SHIFTTYPE', 'Smjena'),
            'GeneralNotes' => _t(__CLASS__ . '.NOTES', 'Opšte napomene'),
            'Zone' => _t(__CLASS__ . '.ZONE', 'Zona'),
            'Zone.Title' => _t(__CLASS__ . '.ZONE', 'Zona'),
            'HandedOverBy' => _t(__CLASS__ . '.HANDEDOVER', 'Predao'),
            'HandedOverBy.Name' => _t(__CLASS__ . '.HANDEDOVER', 'Predao'),
            'ReceivedBy' => _t(__CLASS__ . '.RECEIVED', 'Preuzeo'),
            'ReceivedBy.Name' => _t(__CLASS__ . '.RECEIVED', 'Preuzeo'),
            'CriticalNoteCount' => _t(__CLASS__ . '.CRITICAL', 'Kritične napomene'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('ShiftType', DropdownField::create(
            'ShiftType',
            $this->fieldLabel('ShiftType'),
            ShiftResolver::singleton()->options()
        ));

        if ($this->isInDB()) {
            $html = '';
            foreach ($this->CriticalNotes() as $note) {
                $html .= sprintf(
                    '<li><strong>%s</strong> – %s: %s</li>',
                    htmlspecialchars((string) $note->dbObject('Created')->Nice()),
                    htmlspecialchars($note->Patient()->getFullName()),
                    nl2br(htmlspecialchars((string) $note->NoteText))
                );
            }
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'CriticalNotesList',
                '<div class="form-group"><h4>' . $this->fieldLabel('CriticalNoteCount') . '</h4>'
                . ($html !== '' ? '<ul>' . $html . '</ul>' : '<p>—</p>') . '</div>'
            ));
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->ShiftDate || !Clock::singleton()->parseDate((string) $this->ShiftDate)) {
            $result->addFieldError('ShiftDate', _t(__CLASS__ . '.REQ_DATE', 'Datum smjene je obavezan.'));
        }
        if (!array_key_exists((string) $this->ShiftType, ShiftResolver::singleton()->options())) {
            $result->addFieldError('ShiftType', _t(__CLASS__ . '.INVALID_SHIFT', 'Nepoznata smjena.'));
        }
        if (!$this->ZoneID) {
            $result->addFieldError('ZoneID', _t(__CLASS__ . '.REQ_ZONE', 'Zona je obavezna.'));
        }

        return $result;
    }

    public function getShiftLabel(): string
    {
        return ShiftResolver::singleton()->options()[$this->ShiftType] ?? (string) $this->ShiftType;
    }

    /**
     * Critical notes created during this shift window in this zone or any sub-zone.
     *
     * @return DataList<PatientNote>
     */
    public function CriticalNotes(): DataList
    {
        $date = Clock::singleton()->parseDate((string) $this->ShiftDate);
        $window = $date ? ShiftResolver::singleton()->window($date, (string) $this->ShiftType) : null;
        if (!$window || !$this->ZoneID) {
            return PatientNote::get()->filter('ID', 0);
        }

        $clock = Clock::singleton();

        return PatientNote::get()->filter([
            'Severity' => PatientNote::SEVERITY_CRITICAL,
            'ZoneID' => ZoneScope::singleton()->descendantIds([(int) $this->ZoneID]),
            'Created:GreaterThanOrEqual' => $clock->toDbDatetime($window[0]),
            'Created:LessThan' => $clock->toDbDatetime($window[1]),
        ])->sort('Created', 'ASC');
    }

    public function getCriticalNoteCount(): int
    {
        return $this->CriticalNotes()->count();
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
        return CareHomePermissions::isSuperAdmin($member);
    }
}
