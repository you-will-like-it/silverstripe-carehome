<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Concrete task instance for one resident, one day, one time.
 * Created/removed only by TaskGenerator; status changes go through TaskStatusService (portal)
 * or CMS edits by CareHome admins (both audit-logged).
 *
 * @property string $DueDate
 * @property string $DueTime
 * @property string $Title
 * @property string $Category
 * @property string|null $Medication
 * @property string|null $Dose
 * @property string|null $Route
 * @property string|null $Instructions
 * @property string|null $VitalParameterIDs
 * @property int $Status
 * @property string|null $SkipReason
 * @property string|null $ExecutionNote
 * @property string|null $CompletedAt
 * @property int $PatientID
 * @property int $CompletedByID
 * @property int $TemplateID
 * @method Patient Patient()
 * @method Member CompletedBy()
 * @method TaskTemplate Template()
 * @method HasManyList<VitalReading> VitalReadings()
 */
class DailyTask extends DataObject
{
    public const int STATUS_OPEN = 0;
    public const int STATUS_DONE = 1;
    public const int STATUS_SKIPPED = 2;

    private static string $table_name = 'CareHome_DailyTask';

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'Status' => self::STATUS_OPEN,
    ];

    private static array $db = [
        'DueDate' => 'Date',
        'DueTime' => 'Time',
        'Title' => 'Varchar(200)',
        'Category' => 'Varchar(20)',
        'Medication' => 'Varchar(200)',
        'Dose' => 'Varchar(100)',
        'Route' => 'Varchar(30)',
        'Instructions' => 'Text',
        'VitalParameterIDs' => 'Varchar(255)',
        'Status' => 'Int',
        'SkipReason' => 'Text',
        'ExecutionNote' => 'Text',
        'CompletedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'CompletedBy' => Member::class,
        'Template' => TaskTemplate::class,
    ];

    private static array $has_many = [
        'VitalReadings' => VitalReading::class . '.Task',
    ];

    private static array $cascade_deletes = [
        'VitalReadings',
    ];

    private static array $indexes = [
        // idempotency of the generator, also under concurrent cron runs
        'TaskKey' => ['type' => 'unique', 'columns' => ['PatientID', 'TemplateID', 'DueDate', 'DueTime']],
        'PatientDay' => ['type' => 'index', 'columns' => ['PatientID', 'DueDate', 'Status']],
        'DueDate' => true,
    ];

    private static string $default_sort = '"DueDate" ASC, "DueTime" ASC, "ID" ASC';

    private static array $summary_fields = [
        'DueDate.Nice',
        'DueTime.Short',
        'Title',
        'StatusLabel',
        'CompletedBy.Name',
        'CompletedAt.Nice',
        'SkipReason',
    ];

    private static array $casting = [
        'StatusLabel' => 'Varchar',
        'CategoryLabel' => 'Varchar',
        'TherapyLabel' => 'Varchar',
        'VitalsSummary' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Dnevni zadatak');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Dnevni zadaci');
    }

    /** @return array<int,string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => _t(__CLASS__ . '.STATUS_OPEN', 'Otvoren'),
            self::STATUS_DONE => _t(__CLASS__ . '.STATUS_DONE', 'Odrađen'),
            self::STATUS_SKIPPED => _t(__CLASS__ . '.STATUS_SKIPPED', 'Preskočen'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'DueDate' => _t(__CLASS__ . '.DUEDATE', 'Datum'),
            'DueDate.Nice' => _t(__CLASS__ . '.DUEDATE', 'Datum'),
            'DueTime' => _t(__CLASS__ . '.DUETIME', 'Vrijeme'),
            'DueTime.Short' => _t(__CLASS__ . '.DUETIME', 'Vrijeme'),
            'Title' => _t(__CLASS__ . '.TITLE', 'Zadatak'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'SkipReason' => _t(__CLASS__ . '.SKIPREASON', 'Razlog preskakanja'),
            'ExecutionNote' => _t(__CLASS__ . '.NOTE', 'Napomena pri izvršenju'),
            'CompletedAt' => _t(__CLASS__ . '.COMPLETEDAT', 'Evidentirano'),
            'CompletedAt.Nice' => _t(__CLASS__ . '.COMPLETEDAT', 'Evidentirano'),
            'CompletedBy' => _t(__CLASS__ . '.COMPLETEDBY', 'Radnik'),
            'CompletedBy.Name' => _t(__CLASS__ . '.COMPLETEDBY', 'Radnik'),
            'VitalReadings' => _t(__CLASS__ . '.READINGS', 'Izmjerene vrijednosti'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create();
        $fields->push(\SilverStripe\Forms\TabSet::create('Root', \SilverStripe\Forms\Tab::create('Main')));

        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('PatientRO', _t(__CLASS__ . '.PATIENT', 'Štićenik'), $this->Patient()->getFullName()),
            ReadonlyField::create('WhenRO', $this->fieldLabel('DueDate'), $this->dbObject('DueDate')->Nice() . ' ' . $this->dbObject('DueTime')->Short()),
            ReadonlyField::create('TitleRO', $this->fieldLabel('Title'), $this->Title),
            ReadonlyField::create('TherapyRO', _t(__CLASS__ . '.THERAPY', 'Terapija'), $this->getTherapyLabel()),
            DropdownField::create('Status', $this->fieldLabel('Status'), self::statusOptions())
                ->setDescription(_t(__CLASS__ . '.CMS_EDIT_DESC', 'Svaka izmjena se bilježi u audit log.')),
            TextareaField::create('SkipReason', $this->fieldLabel('SkipReason'))->setRows(2),
            TextareaField::create('ExecutionNote', $this->fieldLabel('ExecutionNote'))->setRows(2),
            ReadonlyField::create('CompletedRO', $this->fieldLabel('CompletedBy'), trim(
                ($this->CompletedByID ? $this->CompletedBy()->getName() : '') . ' ' . ($this->CompletedAt ? $this->dbObject('CompletedAt')->Nice() : '')
            )),
        ]);

        if ($this->isInDB() && $this->getVitalParameterIdList() !== []) {
            $config = GridFieldConfig_RecordEditor::create();
            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $fields->addFieldToTab('Root.Main', GridField::create(
                'VitalReadings',
                $this->fieldLabel('VitalReadings'),
                $this->VitalReadings(),
                $config
            ));
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!array_key_exists((int) $this->Status, self::statusOptions())) {
            $result->addFieldError('Status', _t(__CLASS__ . '.INVALID_STATUS', 'Nepoznat status.'));
        }
        if ((int) $this->Status === self::STATUS_SKIPPED && trim((string) $this->SkipReason) === '') {
            $result->addFieldError('SkipReason', _t(__CLASS__ . '.REQ_SKIPREASON', 'Razlog preskakanja je obavezan.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $status = (int) $this->Status;
        if ($status === self::STATUS_OPEN) {
            $this->CompletedAt = null;
            $this->CompletedByID = 0;
            $this->SkipReason = null;
        } elseif ($this->isChanged('Status', DataObject::CHANGE_VALUE)) {
            // CMS correction without portal context: stamp who/when
            if (!$this->CompletedAt) {
                $this->CompletedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
            }
            if (!$this->CompletedByID && ($member = Security::getCurrentUser())) {
                $this->CompletedByID = (int) $member->ID;
            }
            if ($status === self::STATUS_DONE) {
                $this->SkipReason = null;
            }
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        // re-opened (CMS correction or portal undo): measurements belong to the revoked execution
        $statusChange = $this->getChangedFields(['Status'], DataObject::CHANGE_VALUE)['Status'] ?? null;
        if ($this->isOpen() && $statusChange !== null && (int) $statusChange['before'] !== self::STATUS_OPEN) {
            foreach ($this->VitalReadings() as $reading) {
                $reading->delete();
            }
        }
    }

    public function isOpen(): bool
    {
        return (int) $this->Status === self::STATUS_OPEN;
    }

    public function isDone(): bool
    {
        return (int) $this->Status === self::STATUS_DONE;
    }

    public function isSkipped(): bool
    {
        return (int) $this->Status === self::STATUS_SKIPPED;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[(int) $this->Status] ?? (string) $this->Status;
    }

    public function getCategoryLabel(): string
    {
        return TaskTemplate::categoryOptions()[$this->Category] ?? (string) $this->Category;
    }

    public function getTherapyLabel(): string
    {
        if ($this->Category !== TaskTemplate::CATEGORY_THERAPY) {
            return '';
        }
        $route = TaskTemplate::routeOptions()[$this->Route] ?? '';

        return trim(implode(' · ', array_filter([(string) $this->Medication, (string) $this->Dose, $route])));
    }

    public function getRouteLabel(): string
    {
        return $this->Route ? (TaskTemplate::routeOptions()[$this->Route] ?? (string) $this->Route) : '';
    }

    /** @return list<int> */
    public function getVitalParameterIdList(): array
    {
        return TaskTemplate::decodeIdList($this->VitalParameterIDs);
    }

    /**
     * Definitions to measure for this task, in configured order (inactive ones included:
     * the prescription was made while they were active).
     */
    public function VitalDefinitions(): \SilverStripe\ORM\DataList
    {
        $ids = $this->getVitalParameterIdList();

        return VitalParameterDefinition::get()->filter('ID', $ids ?: [0]);
    }

    public function getVitalsSummary(): string
    {
        $parts = [];
        foreach ($this->VitalReadings()->sort('Definition.SortOrder') as $reading) {
            $parts[] = $reading->DefinitionTitle . ': ' . $reading->getFormattedValue();
        }

        return implode(', ', $parts);
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return Clock::singleton()->combine((string) $this->DueDate, (string) $this->DueTime);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->getDueAt() < Clock::singleton()->now();
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
        return false;
    }

    public function canDelete($member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }
}
