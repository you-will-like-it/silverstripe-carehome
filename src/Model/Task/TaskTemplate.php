<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use DateTimeInterface;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TimeField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HiddenClass;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\TaskGenerator;

/**
 * Shared base for global and individual task templates (multi-table inheritance),
 * so DailyTask can reference any template through a plain has_one.
 *
 * Changes are synchronised to DailyTask immediately (see TaskGenerator::syncPatients()).
 *
 * @property string $Title
 * @property string $Category
 * @property string $ScheduledTime
 * @property string $DaysOfWeek JSON list of ISO weekdays
 * @property string $Medication
 * @property string $Dose
 * @property string $Route
 * @property string $Instructions
 * @property string $VitalParameterIDs JSON list of VitalParameterDefinition IDs
 * @property bool $IsActive
 */
class TaskTemplate extends DataObject implements HiddenClass
{
    public const string CATEGORY_THERAPY = 'Therapy';
    public const string CATEGORY_VITALS = 'Vitals';
    public const string CATEGORY_HYGIENE = 'Hygiene';
    public const string CATEGORY_NUTRITION = 'Nutrition';
    public const string CATEGORY_OTHER = 'Other';

    private static string $table_name = 'CareHome_TaskTemplate';

    /** Administration routes offered in the dropdown (codes; labels via routeOptions()). */
    private static array $route_codes = [
        'Oral', 'Sublingual', 'Subcutaneous', 'Intramuscular', 'Intravenous',
        'Transdermal', 'Inhalation', 'Topical', 'Ophthalmic', 'Rectal', 'Other',
    ];

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'Category' => self::CATEGORY_OTHER,
        'IsActive' => true,
    ];

    private static array $db = [
        'Title' => 'Varchar(200)',
        'Category' => "Enum('Therapy,Vitals,Hygiene,Nutrition,Other','Other')",
        'ScheduledTime' => 'Time',
        'DaysOfWeek' => 'Varchar(64)',
        'Medication' => 'Varchar(200)',
        'Dose' => 'Varchar(100)',
        'Route' => 'Varchar(30)',
        'Instructions' => 'Text',
        'VitalParameterIDs' => 'Varchar(255)',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $indexes = [
        'IsActive' => true,
    ];

    private static string $default_sort = '"ScheduledTime" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Title',
        'CategoryLabel',
        'ScheduledTime.Short',
        'DaysLabel',
        'IsActive.Nice',
    ];

    private static array $casting = [
        'CategoryLabel' => 'Varchar',
        'DaysLabel' => 'Varchar',
        'TherapyLabel' => 'Varchar',
    ];

    /** @var list<int> patient IDs captured before delete */
    private array $patientsBeforeDelete = [];

    /** @return array<string,string> */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_THERAPY => _t(__CLASS__ . '.CAT_THERAPY', 'Terapija'),
            self::CATEGORY_VITALS => _t(__CLASS__ . '.CAT_VITALS', 'Vitalni parametri'),
            self::CATEGORY_HYGIENE => _t(__CLASS__ . '.CAT_HYGIENE', 'Higijena'),
            self::CATEGORY_NUTRITION => _t(__CLASS__ . '.CAT_NUTRITION', 'Ishrana'),
            self::CATEGORY_OTHER => _t(__CLASS__ . '.CAT_OTHER', 'Ostalo'),
        ];
    }

    /** @return array<string,string> */
    public static function routeOptions(): array
    {
        $labels = [
            'Oral' => _t(__CLASS__ . '.ROUTE_ORAL', 'Oralno (na usta)'),
            'Sublingual' => _t(__CLASS__ . '.ROUTE_SUBLINGUAL', 'Pod jezik'),
            'Subcutaneous' => _t(__CLASS__ . '.ROUTE_SC', 'Potkožno (s.c.)'),
            'Intramuscular' => _t(__CLASS__ . '.ROUTE_IM', 'Intramuskularno (i.m.)'),
            'Intravenous' => _t(__CLASS__ . '.ROUTE_IV', 'Intravenski (i.v.)'),
            'Transdermal' => _t(__CLASS__ . '.ROUTE_TRANSDERMAL', 'Flaster (transdermalno)'),
            'Inhalation' => _t(__CLASS__ . '.ROUTE_INHALATION', 'Inhalacija'),
            'Topical' => _t(__CLASS__ . '.ROUTE_TOPICAL', 'Lokalno na kožu'),
            'Ophthalmic' => _t(__CLASS__ . '.ROUTE_OPHTHALMIC', 'Kapi za oči/uši'),
            'Rectal' => _t(__CLASS__ . '.ROUTE_RECTAL', 'Rektalno'),
            'Other' => _t(__CLASS__ . '.ROUTE_OTHER', 'Ostalo'),
        ];
        $options = [];
        foreach ((array) static::config()->get('route_codes') as $code) {
            $options[$code] = $labels[$code] ?? $code;
        }

        return $options;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv zadatka'),
            'Category' => _t(__CLASS__ . '.CATEGORY', 'Kategorija'),
            'CategoryLabel' => _t(__CLASS__ . '.CATEGORY', 'Kategorija'),
            'ScheduledTime' => _t(__CLASS__ . '.TIME', 'Vrijeme'),
            'ScheduledTime.Short' => _t(__CLASS__ . '.TIME', 'Vrijeme'),
            'DaysOfWeek' => _t(__CLASS__ . '.DAYS', 'Dani u sedmici'),
            'DaysLabel' => _t(__CLASS__ . '.DAYS', 'Dani u sedmici'),
            'Medication' => _t(__CLASS__ . '.MEDICATION', 'Lijek'),
            'Dose' => _t(__CLASS__ . '.DOSE', 'Doza'),
            'Route' => _t(__CLASS__ . '.ROUTE', 'Način primjene'),
            'Instructions' => _t(__CLASS__ . '.INSTRUCTIONS', 'Uputa za radnika'),
            'VitalParameterIDs' => _t(__CLASS__ . '.VITALS', 'Parametri za mjerenje'),
            'IsActive' => _t(__CLASS__ . '.ISACTIVE', 'Aktivan'),
            'IsActive.Nice' => _t(__CLASS__ . '.ISACTIVE', 'Aktivan'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([
            'Title', 'Category', 'ScheduledTime', 'DaysOfWeek', 'Medication', 'Dose',
            'Route', 'Instructions', 'VitalParameterIDs', 'IsActive',
        ]);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Title', $this->fieldLabel('Title')),
            DropdownField::create('Category', $this->fieldLabel('Category'), self::categoryOptions()),
            TimeField::create('ScheduledTime', $this->fieldLabel('ScheduledTime')),
            CheckboxSetField::create('DaysOfWeek', $this->fieldLabel('DaysOfWeek'), DaysOfWeek::options()),
            CheckboxField::create('IsActive', $this->fieldLabel('IsActive')),
            TextareaField::create('Instructions', $this->fieldLabel('Instructions'))->setRows(2),

            HeaderField::create('TherapyHeader', _t(__CLASS__ . '.THERAPY_HEADER', 'Terapija (samo za kategoriju „Terapija")'), 4),
            TextField::create('Medication', $this->fieldLabel('Medication'))
                ->setDescription(_t(__CLASS__ . '.MEDICATION_DESC', 'Naziv i jačina, npr. „Metformin 500 mg".')),
            TextField::create('Dose', $this->fieldLabel('Dose'))
                ->setDescription(_t(__CLASS__ . '.DOSE_DESC', 'Količina po jednoj primjeni, npr. „1 tableta", „10 IJ", „5 ml".')),
            DropdownField::create('Route', $this->fieldLabel('Route'), self::routeOptions())
                ->setEmptyString('—'),

            HeaderField::create('VitalsHeader', _t(__CLASS__ . '.VITALS_HEADER', 'Vitalni parametri (samo za kategoriju „Vitalni parametri")'), 4),
            ListboxField::create(
                'VitalParameterIDs',
                $this->fieldLabel('VitalParameterIDs'),
                VitalParameterDefinition::get()->filter('IsActive', true)->map('ID', 'LabelWithUnit')
            ),
        ]);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(__CLASS__ . '.REQ_TITLE', 'Naziv je obavezan.'));
        }
        if (!$this->ScheduledTime) {
            $result->addFieldError('ScheduledTime', _t(__CLASS__ . '.REQ_TIME', 'Vrijeme je obavezno.'));
        }
        if ($this->requiresDays() && $this->getDays() === []) {
            $result->addFieldError('DaysOfWeek', _t(__CLASS__ . '.REQ_DAYS', 'Odaberite barem jedan dan.'));
        }
        if ($this->Category === self::CATEGORY_THERAPY) {
            if (trim((string) $this->Medication) === '') {
                $result->addFieldError('Medication', _t(__CLASS__ . '.REQ_MEDICATION', 'Lijek je obavezan za terapiju.'));
            }
            if (trim((string) $this->Dose) === '') {
                $result->addFieldError('Dose', _t(__CLASS__ . '.REQ_DOSE', 'Doza je obavezna za terapiju.'));
            }
        }
        if ($this->Route && !array_key_exists((string) $this->Route, self::routeOptions())) {
            $result->addFieldError('Route', _t(__CLASS__ . '.INVALID_ROUTE', 'Nepoznat način primjene.'));
        }
        if ($this->Category === self::CATEGORY_VITALS && $this->getVitalParameterIdList() === []) {
            $result->addFieldError('VitalParameterIDs', _t(__CLASS__ . '.REQ_VITALS', 'Odaberite barem jedan parametar.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        // normalise storage regardless of which form/importer wrote it
        $this->DaysOfWeek = DaysOfWeek::encode($this->getDays());
        $this->VitalParameterIDs = $this->Category === self::CATEGORY_VITALS
            ? (string) json_encode(array_map('strval', $this->getVitalParameterIdList()))
            : null;

        if ($this->Category !== self::CATEGORY_THERAPY) {
            $this->Medication = null;
            $this->Dose = null;
            $this->Route = null;
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        TaskGenerator::singleton()->syncPatients($this->affectedPatientIds());
    }

    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        $this->patientsBeforeDelete = $this->affectedPatientIds();
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        TaskGenerator::singleton()->syncPatients($this->patientsBeforeDelete);
    }

    /**
     * Patients whose DailyTasks depend on this template.
     *
     * @return list<int>
     */
    public function affectedPatientIds(): array
    {
        return [];
    }

    /**
     * Does this template produce a task on the given calendar day?
     */
    public function occursOn(DateTimeInterface $date): bool
    {
        return false;
    }

    protected function requiresDays(): bool
    {
        return true;
    }

    /** @return list<int> */
    public function getDays(): array
    {
        return DaysOfWeek::parse($this->DaysOfWeek);
    }

    public function getDaysLabel(): string
    {
        return DaysOfWeek::label($this->getDays());
    }

    /** @return list<int> */
    public function getVitalParameterIdList(): array
    {
        return self::decodeIdList($this->VitalParameterIDs);
    }

    public function getCategoryLabel(): string
    {
        return self::categoryOptions()[$this->Category] ?? (string) $this->Category;
    }

    /**
     * Values copied onto each generated DailyTask (snapshot = what was prescribed at that time).
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'Title' => (string) $this->Title,
            'Category' => (string) $this->Category,
            'Medication' => $this->Medication ?: null,
            'Dose' => $this->Dose ?: null,
            'Route' => $this->Route ?: null,
            'Instructions' => $this->Instructions ?: null,
            'VitalParameterIDs' => $this->VitalParameterIDs ?: null,
        ];
    }

    /** @return list<int> */
    public static function decodeIdList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn (int $id): bool => $id > 0)));
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
        return static::class !== self::class && CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canDelete($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }
}
