<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Patient;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldConfig_RelationEditor;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\ManyManyThroughList;
use YouWillLikeIT\CareHome\Control\Admin\MedicalReportController;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Kitchen\PatientMealSelection;
use YouWillLikeIT\CareHome\Model\Note\PatientNote;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\GlobalTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\IndividualTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\PatientGlobalTask;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\BedAssignmentService;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\PatientLifecycleService;

/**
 * Resident.
 *
 * Bed 1:1 invariant: BedLock mirrors BedID only while the resident is Active and has a bed,
 * otherwise NULL. A UNIQUE index on BedLock (NULLs allowed multiple times) guarantees
 * on DB level that a bed can never hold two residents, even under concurrent writes.
 *
 * @property string $FirstName
 * @property string $LastName
 * @property string|null $DateOfBirth
 * @property string $JMBG
 * @property string|null $AdmissionDate
 * @property string $DietaryRequirements
 * @property string $MedicalAlerts
 * @property string $AdminNotes
 * @property string $EmergencyContactName
 * @property string $EmergencyContactPhone
 * @property string $EmergencyContactRelation
 * @property string $Status
 * @property bool $IsTemporarilyAbsent
 * @property string $AbsenceNote
 * @property string|null $ExitDate
 * @property string $ExitReason
 * @property string|null $BedLock
 * @property int $BedID
 * @property int $PhotoID
 * @method FacilityBed Bed()
 * @method Image Photo()
 * @method HasManyList<DailyTask> DailyTasks()
 * @method HasManyList<PatientNote> Notes()
 * @method HasManyList<PatientMealSelection> MealSelections()
 * @method HasManyList<IndividualTaskTemplate> IndividualTasks()
 * @method HasManyList<BedAssignment> BedAssignments()
 * @method ManyManyThroughList<GlobalTaskTemplate> AssignedGlobalTasks()
 * @method ManyManyList<DietaryTag> DietaryTags()
 */
class Patient extends DataObject
{
    public const string STATUS_ACTIVE = 'Active';
    public const string STATUS_DISCHARGED = 'Discharged';
    public const string STATUS_DECEASED = 'Deceased';
    public const string STATUS_TRANSFERRED = 'Transferred';

    private static string $table_name = 'CareHome_Patient';

    /** Validate JMBG (13 digits + ex-YU modulo-11 checksum). Disable for DACH installs. */
    private static bool $validate_jmbg = true;

    /** Upload folder for resident photos (files stay unpublished = protected). */
    private static string $photo_folder = 'carehome-patients';

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'Status' => self::STATUS_ACTIVE,
    ];

    private static array $db = [
        'FirstName' => 'Varchar(100)',
        'LastName' => 'Varchar(100)',
        'DateOfBirth' => 'Date',
        'JMBG' => 'Varchar(13)',
        'AdmissionDate' => 'Date',
        'DietaryRequirements' => 'Text',
        'MedicalAlerts' => 'Text',
        'AdminNotes' => 'HTMLText',
        'EmergencyContactName' => 'Varchar(150)',
        'EmergencyContactPhone' => 'Varchar(50)',
        'EmergencyContactRelation' => 'Varchar(100)',
        'Status' => "Enum('Active,Discharged,Deceased,Transferred','Active')",
        'IsTemporarilyAbsent' => 'Boolean',
        'AbsenceNote' => 'Varchar(255)',
        'ExitDate' => 'Date',
        'ExitReason' => 'Text',
        // internal: see class docblock
        'BedLock' => 'Varchar(20)',
    ];

    private static array $has_one = [
        'Bed' => FacilityBed::class,
        'Photo' => Image::class,
    ];

    private static array $has_many = [
        'DailyTasks' => DailyTask::class . '.Patient',
        'Notes' => PatientNote::class . '.Patient',
        'MealSelections' => PatientMealSelection::class . '.Patient',
        'IndividualTasks' => IndividualTaskTemplate::class . '.Patient',
        'BedAssignments' => BedAssignment::class . '.Patient',
    ];

    private static array $many_many = [
        'AssignedGlobalTasks' => [
            'through' => PatientGlobalTask::class,
            'from' => 'Patient',
            'to' => 'Template',
        ],
        'DietaryTags' => DietaryTag::class,
    ];

    private static array $indexes = [
        'BedLock' => ['type' => 'unique', 'columns' => ['BedLock']],
        'Status' => true,
        'LastName' => true,
    ];

    private static string $default_sort = '"LastName" ASC, "FirstName" ASC';

    private static array $summary_fields = [
        'LastName',
        'FirstName',
        'DateOfBirth.Nice',
        'BedTitle',
        'StatusLabel',
    ];

    private static array $searchable_fields = [
        'LastName',
        'FirstName',
        'JMBG',
        'Status',
    ];

    private static array $casting = [
        'FullName' => 'Varchar',
        'BedTitle' => 'Varchar',
        'StatusLabel' => 'Varchar',
        'Age' => 'Int',
    ];

    private bool $careWasNew = false;

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Štićenik');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Štićenici');
    }

    /** @return array<string,string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => _t(__CLASS__ . '.STATUS_ACTIVE', 'Aktivan'),
            self::STATUS_DISCHARGED => _t(__CLASS__ . '.STATUS_DISCHARGED', 'Otpušten'),
            self::STATUS_DECEASED => _t(__CLASS__ . '.STATUS_DECEASED', 'Preminuo'),
            self::STATUS_TRANSFERRED => _t(__CLASS__ . '.STATUS_TRANSFERRED', 'Premješten'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'FirstName' => _t(__CLASS__ . '.FIRSTNAME', 'Ime'),
            'LastName' => _t(__CLASS__ . '.LASTNAME', 'Prezime'),
            'DateOfBirth' => _t(__CLASS__ . '.DOB', 'Datum rođenja'),
            'DateOfBirth.Nice' => _t(__CLASS__ . '.DOB', 'Datum rođenja'),
            'JMBG' => _t(__CLASS__ . '.JMBG', 'JMBG'),
            'AdmissionDate' => _t(__CLASS__ . '.ADMISSION', 'Datum prijema'),
            'DietaryRequirements' => _t(__CLASS__ . '.DIET', 'Ishrana / dijetetski zahtjevi'),
            'MedicalAlerts' => _t(__CLASS__ . '.ALERTS', 'Alergije i dijagnoze'),
            'AdminNotes' => _t(__CLASS__ . '.ADMINNOTES', 'Napomene pri prijemu'),
            'EmergencyContactName' => _t(__CLASS__ . '.EC_NAME', 'Hitni kontakt – ime'),
            'EmergencyContactPhone' => _t(__CLASS__ . '.EC_PHONE', 'Hitni kontakt – telefon'),
            'EmergencyContactRelation' => _t(__CLASS__ . '.EC_RELATION', 'Hitni kontakt – srodstvo'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'IsTemporarilyAbsent' => _t(__CLASS__ . '.ABSENT', 'Privremeno odsutan'),
            'AbsenceNote' => _t(__CLASS__ . '.ABSENCE_NOTE', 'Napomena o odsustvu'),
            'ExitDate' => _t(__CLASS__ . '.EXITDATE', 'Datum prestanka'),
            'ExitReason' => _t(__CLASS__ . '.EXITREASON', 'Razlog prestanka'),
            'Bed' => _t(__CLASS__ . '.BED', 'Krevet'),
            'BedTitle' => _t(__CLASS__ . '.BED', 'Krevet'),
            'Photo' => _t(__CLASS__ . '.PHOTO', 'Fotografija'),
            'DietaryTags' => _t(__CLASS__ . '.DIETTAGS', 'Dijetetske oznake'),
            'AssignedGlobalTasks' => _t(__CLASS__ . '.GLOBALTASKS', 'Globalni zadaci'),
            'IndividualTasks' => _t(__CLASS__ . '.INDIVIDUALTASKS', 'Individualni zadaci / terapija'),
            'DailyTasks' => _t(__CLASS__ . '.DAILYTASKS', 'Dnevni zadaci'),
            'Notes' => _t(__CLASS__ . '.NOTES', 'Napomene'),
            'MealSelections' => _t(__CLASS__ . '.MEALS', 'Izbor obroka'),
            'BedAssignments' => _t(__CLASS__ . '.BEDHISTORY', 'Historija kreveta'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(TabSet::create('Root', Tab::create('Main', _t(__CLASS__ . '.TAB_MAIN', 'Osnovno'))));

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('FirstName', $this->fieldLabel('FirstName')),
            TextField::create('LastName', $this->fieldLabel('LastName')),
            DateField::create('DateOfBirth', $this->fieldLabel('DateOfBirth')),
            TextField::create('JMBG', $this->fieldLabel('JMBG'))->setMaxLength(13),
            DateField::create('AdmissionDate', $this->fieldLabel('AdmissionDate')),
            UploadField::create('Photo', $this->fieldLabel('Photo'))
                ->setFolderName((string) static::config()->get('photo_folder'))
                ->setAllowedFileCategories('image/supported')
                ->setDescription(_t(
                    __CLASS__ . '.PHOTO_DESC',
                    'Fotografija ostaje neobjavljena (zaštićena) i prikazuje se samo prijavljenim radnicima.'
                )),
        ]);

        $tabStay = 'Root.' . _t(__CLASS__ . '.TAB_STAY', 'Smještaj i status');
        $fields->addFieldsToTab($tabStay, [
            DropdownField::create(
                'BedID',
                $this->fieldLabel('Bed'),
                BedAssignmentService::singleton()->bedOptions($this->BedID ? (int) $this->BedID : null)
            )->setEmptyString(_t(__CLASS__ . '.NO_BED', '— bez kreveta —'))
                ->setDescription(_t(
                    __CLASS__ . '.BED_DESC',
                    'Prikazani su samo slobodni kreveti. Neaktivni štićenik automatski oslobađa krevet.'
                )),
            DropdownField::create('Status', $this->fieldLabel('Status'), self::statusOptions()),
            CheckboxField::create('IsTemporarilyAbsent', $this->fieldLabel('IsTemporarilyAbsent'))
                ->setDescription(_t(
                    __CLASS__ . '.ABSENT_DESC',
                    'Krevet ostaje rezervisan. Ne generišu se zadaci niti izbor obroka.'
                )),
            TextField::create('AbsenceNote', $this->fieldLabel('AbsenceNote')),
            HeaderField::create('ExitHeader', _t(__CLASS__ . '.EXIT_HEADER', 'Prestanak boravka'), 4),
            DateField::create('ExitDate', $this->fieldLabel('ExitDate'))
                ->setDescription(_t(__CLASS__ . '.EXIT_DESC', 'Obavezno ako status nije „Aktivan".')),
            TextareaField::create('ExitReason', $this->fieldLabel('ExitReason'))->setRows(3),
        ]);

        $tabHealth = 'Root.' . _t(__CLASS__ . '.TAB_HEALTH', 'Zdravlje i ishrana');
        $fields->addFieldsToTab($tabHealth, [
            TextareaField::create('MedicalAlerts', $this->fieldLabel('MedicalAlerts'))->setRows(4),
            TextareaField::create('DietaryRequirements', $this->fieldLabel('DietaryRequirements'))->setRows(3),
            ListboxField::create('DietaryTags', $this->fieldLabel('DietaryTags'), DietaryTag::get()->map('ID', 'Title'))
                ->setDescription(_t(
                    __CLASS__ . '.DIETTAGS_DESC',
                    'Opcionalno: jela označena kao neprikladna za ove oznake neće se nuditi.'
                )),
            HTMLEditorField::create('AdminNotes', $this->fieldLabel('AdminNotes'))->setRows(8),
        ]);

        $tabContact = 'Root.' . _t(__CLASS__ . '.TAB_CONTACT', 'Hitni kontakt');
        $fields->addFieldsToTab($tabContact, [
            TextField::create('EmergencyContactName', $this->fieldLabel('EmergencyContactName')),
            TextField::create('EmergencyContactPhone', $this->fieldLabel('EmergencyContactPhone')),
            TextField::create('EmergencyContactRelation', $this->fieldLabel('EmergencyContactRelation')),
        ]);

        if ($this->isInDB()) {
            $this->addRelationTabs($fields);
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    private function addRelationTabs(FieldList $fields): void
    {
        $tabTasks = 'Root.' . _t(__CLASS__ . '.TAB_TASKS', 'Zadaci');

        $globalConfig = GridFieldConfig_RelationEditor::create();
        $globalConfig->removeComponentsByType(GridFieldAddNewButton::class);

        $dailyConfig = GridFieldConfig_RecordEditor::create(50);
        $dailyConfig->removeComponentsByType(GridFieldAddNewButton::class);

        $fields->addFieldsToTab($tabTasks, [
            GridField::create(
                'AssignedGlobalTasks',
                $this->fieldLabel('AssignedGlobalTasks'),
                $this->AssignedGlobalTasks(),
                $globalConfig
            ),
            GridField::create(
                'IndividualTasks',
                $this->fieldLabel('IndividualTasks'),
                $this->IndividualTasks(),
                GridFieldConfig_RecordEditor::create()
            ),
        ]);

        $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_DAILY', 'Dnevna evidencija'), GridField::create(
            'DailyTasks',
            $this->fieldLabel('DailyTasks'),
            $this->DailyTasks()->sort(['DueDate' => 'DESC', 'DueTime' => 'DESC']),
            $dailyConfig
        ));

        $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_NOTES', 'Napomene'), GridField::create(
            'Notes',
            $this->fieldLabel('Notes'),
            $this->Notes()->sort('Created', 'DESC'),
            GridFieldConfig_RecordEditor::create()
        ));

        $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_MEALS', 'Obroci'), GridField::create(
            'MealSelections',
            $this->fieldLabel('MealSelections'),
            $this->MealSelections()->sort('SelectedDate', 'DESC'),
            GridFieldConfig_RecordViewer::create()
        ));

        $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_STAY', 'Smještaj i status'), GridField::create(
            'BedAssignments',
            $this->fieldLabel('BedAssignments'),
            $this->BedAssignments(),
            GridFieldConfig_RecordViewer::create()
        ));

        if (CareHomePermissions::check(CareHomePermissions::REPORTS)) {
            $links = [];
            foreach ([7, 14, 30] as $days) {
                $links[] = sprintf(
                    '<a class="btn btn-outline-primary font-icon-down-circled" target="_blank" rel="noopener" href="%s">%s</a>',
                    htmlspecialchars(MedicalReportController::pdfLinkForDays((int) $this->ID, $days)),
                    _t(__CLASS__ . '.REPORT_DAYS', 'Zadnjih {days} dana', ['days' => $days])
                );
            }
            $links[] = sprintf(
                '<a class="btn btn-outline-secondary font-icon-calendar" target="_blank" rel="noopener" href="%s">%s</a>',
                htmlspecialchars(MedicalReportController::formLink((int) $this->ID)),
                _t(__CLASS__ . '.REPORT_CUSTOM', 'Proizvoljan period…')
            );

            $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_REPORT', 'Ljekarski izvještaj'), LiteralField::create(
                'ReportLinks',
                '<div class="form-group"><p>' . _t(
                    __CLASS__ . '.REPORT_INTRO',
                    'PDF sa vitalnim parametrima, terapijom (uključujući preskočene doze) i napomenama.'
                ) . '</p><p style="display:flex;gap:.5rem;flex-wrap:wrap">' . implode('', $links) . '</p></div>'
            ));
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->FirstName) === '') {
            $result->addFieldError('FirstName', _t(__CLASS__ . '.REQ_FIRSTNAME', 'Ime je obavezno.'));
        }
        if (trim((string) $this->LastName) === '') {
            $result->addFieldError('LastName', _t(__CLASS__ . '.REQ_LASTNAME', 'Prezime je obavezno.'));
        }

        $jmbg = trim((string) $this->JMBG);
        if ($jmbg !== '' && static::config()->get('validate_jmbg') && !self::isValidJmbg($jmbg)) {
            $result->addFieldError('JMBG', _t(__CLASS__ . '.INVALID_JMBG', 'JMBG nije ispravan (13 cifara, kontrolna cifra).'));
        }

        if (!array_key_exists((string) $this->Status, self::statusOptions())) {
            $result->addFieldError('Status', _t(__CLASS__ . '.INVALID_STATUS', 'Nepoznat status.'));
        }

        if ($this->Status !== self::STATUS_ACTIVE) {
            if (!$this->ExitDate) {
                $result->addFieldError('ExitDate', _t(__CLASS__ . '.REQ_EXITDATE', 'Datum prestanka je obavezan za neaktivnog štićenika.'));
            }
            if (trim((string) $this->ExitReason) === '') {
                $result->addFieldError('ExitReason', _t(__CLASS__ . '.REQ_EXITREASON', 'Razlog prestanka je obavezan.'));
            }
        }

        if ($this->ExitDate && $this->AdmissionDate && $this->ExitDate < $this->AdmissionDate) {
            $result->addFieldError('ExitDate', _t(__CLASS__ . '.EXIT_BEFORE_ADMISSION', 'Datum prestanka je prije datuma prijema.'));
        }

        if ($this->Status === self::STATUS_ACTIVE && $this->BedID) {
            $bed = FacilityBed::get()->byID((int) $this->BedID);
            if (!$bed) {
                $result->addFieldError('BedID', _t(__CLASS__ . '.BED_MISSING', 'Odabrani krevet ne postoji.'));
            } else {
                $holder = Patient::get()
                    ->filter('BedLock', (string) $this->BedID)
                    ->exclude('ID', (int) $this->ID)
                    ->first();
                if ($holder) {
                    $result->addFieldError('BedID', _t(
                        __CLASS__ . '.BED_TAKEN',
                        'Krevet je već dodijeljen štićeniku {name}.',
                        ['name' => $holder->getFullName()]
                    ));
                }
            }
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $this->careWasNew = !$this->isInDB();

        if ($this->Status !== self::STATUS_ACTIVE) {
            $this->BedID = 0;
            $this->IsTemporarilyAbsent = false;
        } else {
            // re-admission: exit data belongs to the previous stay (kept in audit log)
            $this->ExitDate = null;
            $this->ExitReason = null;
        }

        if (!$this->IsTemporarilyAbsent) {
            $this->AbsenceNote = null;
        }

        $this->BedLock = ($this->Status === self::STATUS_ACTIVE && $this->BedID) ? (string) $this->BedID : null;

        if (!$this->AdmissionDate && $this->careWasNew) {
            $this->AdmissionDate = Clock::singleton()->today()->format('Y-m-d');
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();

        PatientLifecycleService::singleton()->afterPatientWrite(
            $this,
            $this->getChangedFields(['BedID', 'Status', 'IsTemporarilyAbsent'], DataObject::CHANGE_VALUE),
            $this->careWasNew
        );
    }

    public static function isValidJmbg(string $jmbg): bool
    {
        if (!preg_match('/^\d{13}$/', $jmbg)) {
            return false;
        }
        $d = array_map('intval', str_split($jmbg));
        $sum = 7 * ($d[0] + $d[6]) + 6 * ($d[1] + $d[7]) + 5 * ($d[2] + $d[8])
            + 4 * ($d[3] + $d[9]) + 3 * ($d[4] + $d[10]) + 2 * ($d[5] + $d[11]);
        $control = 11 - ($sum % 11);
        if ($control > 9) {
            $control = 0;
        }

        return $control === $d[12];
    }

    public function getTitle(): string
    {
        return $this->getFullName();
    }

    public function getFullName(): string
    {
        return trim($this->FirstName . ' ' . $this->LastName);
    }

    public function getStatusLabel(): string
    {
        $label = self::statusOptions()[$this->Status] ?? (string) $this->Status;
        if ($this->Status === self::STATUS_ACTIVE && $this->IsTemporarilyAbsent) {
            $label .= ' – ' . _t(__CLASS__ . '.ABSENT', 'Privremeno odsutan');
        }

        return $label;
    }

    public function getBedTitle(): string
    {
        $bed = $this->BedID ? $this->Bed() : null;

        return $bed && $bed->exists() ? $bed->getFullTitle() : '—';
    }

    public function getAge(): ?int
    {
        if (!$this->DateOfBirth) {
            return null;
        }
        $dob = Clock::singleton()->parseDate((string) $this->DateOfBirth);

        return $dob ? $dob->diff(Clock::singleton()->today())->y : null;
    }

    public function isActive(): bool
    {
        return $this->Status === self::STATUS_ACTIVE;
    }

    /**
     * Active and physically present: tasks are generated and meals may be chosen.
     */
    public function isAvailableForCare(): bool
    {
        return $this->isActive() && !$this->IsTemporarilyAbsent;
    }

    public function hasPhoto(): bool
    {
        return $this->PhotoID && $this->Photo()->exists();
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

    /**
     * Medical records are not deleted in normal operation; set an exit status instead.
     */
    public function canDelete($member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }
}
