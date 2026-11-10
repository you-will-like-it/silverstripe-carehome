<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Note;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\AuditContext;

/**
 * Quick shift note about a resident. ZoneID is snapshotted at creation so shift handover
 * aggregation stays correct after the resident moves.
 *
 * @property string $NoteText
 * @property string $Severity
 * @property int $PatientID
 * @property int $CreatedByID
 * @property int $ZoneID
 * @method Patient Patient()
 * @method Member CreatedBy()
 * @method FacilityZone Zone()
 */
class PatientNote extends DataObject
{
    public const string SEVERITY_INFO = 'Info';
    public const string SEVERITY_IMPORTANT = 'Important';
    public const string SEVERITY_CRITICAL = 'Critical';

    private static string $table_name = 'CareHome_PatientNote';

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'Severity' => self::SEVERITY_INFO,
    ];

    private static array $db = [
        'NoteText' => 'Text',
        'Severity' => "Enum('Info,Important,Critical','Info')",
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'CreatedBy' => Member::class,
        'Zone' => FacilityZone::class,
    ];

    private static array $indexes = [
        'SeverityCreated' => ['type' => 'index', 'columns' => ['Severity', 'Created']],
        'PatientCreated' => ['type' => 'index', 'columns' => ['PatientID', 'Created']],
    ];

    private static string $default_sort = '"Created" DESC';

    private static array $summary_fields = [
        'Created.Nice',
        'SeverityLabel',
        'NoteText',
        'CreatedBy.Name',
    ];

    private static array $casting = [
        'SeverityLabel' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Napomena');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Napomene');
    }

    /** @return array<string,string> */
    public static function severityOptions(): array
    {
        return [
            self::SEVERITY_INFO => _t(__CLASS__ . '.SEV_INFO', 'Info'),
            self::SEVERITY_IMPORTANT => _t(__CLASS__ . '.SEV_IMPORTANT', 'Važno'),
            self::SEVERITY_CRITICAL => _t(__CLASS__ . '.SEV_CRITICAL', 'Kritično'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'NoteText' => _t(__CLASS__ . '.TEXT', 'Napomena'),
            'Severity' => _t(__CLASS__ . '.SEVERITY', 'Važnost'),
            'SeverityLabel' => _t(__CLASS__ . '.SEVERITY', 'Važnost'),
            'Created.Nice' => _t(__CLASS__ . '.CREATED', 'Vrijeme'),
            'CreatedBy.Name' => _t(__CLASS__ . '.CREATEDBY', 'Autor'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['PatientID', 'CreatedByID', 'ZoneID', 'Severity']);
        $fields->addFieldToTab(
            'Root.Main',
            DropdownField::create('Severity', $this->fieldLabel('Severity'), self::severityOptions()),
            'NoteText'
        );
        $fields->replaceField('NoteText', TextareaField::create('NoteText', $this->fieldLabel('NoteText'))->setRows(4));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->NoteText) === '') {
            $result->addFieldError('NoteText', _t(__CLASS__ . '.REQ_TEXT', 'Tekst napomene je obavezan.'));
        }
        if (!array_key_exists((string) $this->Severity, self::severityOptions())) {
            $result->addFieldError('Severity', _t(__CLASS__ . '.INVALID_SEVERITY', 'Nepoznata važnost.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->isInDB()) {
            if (!$this->CreatedByID && ($actor = AuditContext::singleton()->getActor())) {
                $this->CreatedByID = (int) $actor->ID;
            }
            if (!$this->ZoneID && $this->PatientID) {
                $bed = $this->Patient()->Bed();
                if ($bed && $bed->exists()) {
                    $this->ZoneID = (int) $bed->Room()->ZoneID;
                }
            }
        }
    }

    public function getSeverityLabel(): string
    {
        return self::severityOptions()[$this->Severity] ?? (string) $this->Severity;
    }

    public function isCritical(): bool
    {
        return $this->Severity === self::SEVERITY_CRITICAL;
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
