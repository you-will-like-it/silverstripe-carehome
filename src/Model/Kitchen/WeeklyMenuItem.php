<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Kitchen;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use YouWillLikeIT\CareHome\Model\Patient\DietaryTag;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * One menu option for a date and meal (lunch/dinner).
 *
 * @property string $MenuDate
 * @property string $MealType
 * @property string $OptionName
 * @property string $Description
 * @method ManyManyList<DietaryTag> NotSuitableFor()
 * @method HasManyList<PatientMealSelection> Selections()
 */
class WeeklyMenuItem extends DataObject
{
    public const string MEAL_LUNCH = 'Lunch';
    public const string MEAL_DINNER = 'Dinner';

    private static string $table_name = 'CareHome_WeeklyMenuItem';

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'MealType' => self::MEAL_LUNCH,
    ];

    private static array $db = [
        'MenuDate' => 'Date',
        'MealType' => "Enum('Lunch,Dinner','Lunch')",
        'OptionName' => 'Varchar(150)',
        'Description' => 'Text',
    ];

    private static array $many_many = [
        'NotSuitableFor' => DietaryTag::class,
    ];

    private static array $has_many = [
        'Selections' => PatientMealSelection::class . '.MenuItem',
    ];

    private static array $indexes = [
        'DateMeal' => ['type' => 'index', 'columns' => ['MenuDate', 'MealType']],
    ];

    private static string $default_sort = '"MenuDate" DESC, "MealType" ASC, "OptionName" ASC';

    private static array $summary_fields = [
        'MenuDate.Nice',
        'MealTypeLabel',
        'OptionName',
        'UnsuitableLabel',
        'SelectionCount',
    ];

    private static array $searchable_fields = [
        'MenuDate',
        'MealType',
        'OptionName',
    ];

    private static array $casting = [
        'MealTypeLabel' => 'Varchar',
        'UnsuitableLabel' => 'Varchar',
        'SelectionCount' => 'Int',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Stavka jelovnika');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Jelovnik');
    }

    /** @return array<string,string> */
    public static function mealTypeOptions(): array
    {
        return [
            self::MEAL_LUNCH => _t(__CLASS__ . '.LUNCH', 'Ručak'),
            self::MEAL_DINNER => _t(__CLASS__ . '.DINNER', 'Večera'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'MenuDate' => _t(__CLASS__ . '.DATE', 'Datum'),
            'MenuDate.Nice' => _t(__CLASS__ . '.DATE', 'Datum'),
            'MealType' => _t(__CLASS__ . '.MEALTYPE', 'Obrok'),
            'MealTypeLabel' => _t(__CLASS__ . '.MEALTYPE', 'Obrok'),
            'OptionName' => _t(__CLASS__ . '.OPTION', 'Naziv menija'),
            'Description' => _t(__CLASS__ . '.DESCRIPTION', 'Opis'),
            'NotSuitableFor' => _t(__CLASS__ . '.NOTSUITABLE', 'Nije prikladno za'),
            'UnsuitableLabel' => _t(__CLASS__ . '.NOTSUITABLE', 'Nije prikladno za'),
            'SelectionCount' => _t(__CLASS__ . '.SELECTIONS', 'Odabrano'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['NotSuitableFor', 'Selections', 'MealType']);
        $fields->addFieldToTab(
            'Root.Main',
            DropdownField::create('MealType', $this->fieldLabel('MealType'), self::mealTypeOptions()),
            'OptionName'
        );
        $fields->addFieldToTab('Root.Main', ListboxField::create(
            'NotSuitableFor',
            $this->fieldLabel('NotSuitableFor'),
            DietaryTag::get()->map('ID', 'Title')
        )->setDescription(_t(
            __CLASS__ . '.NOTSUITABLE_DESC',
            'Opcionalno. Štićenicima s ovim oznakama jelo se neće nuditi.'
        )));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->MenuDate) {
            $result->addFieldError('MenuDate', _t(__CLASS__ . '.REQ_DATE', 'Datum je obavezan.'));
        }
        if (trim((string) $this->OptionName) === '') {
            $result->addFieldError('OptionName', _t(__CLASS__ . '.REQ_OPTION', 'Naziv menija je obavezan.'));
        }
        if ($this->isInDB() && $this->isChanged('MenuDate') && $this->Selections()->exists()) {
            $result->addFieldError('MenuDate', _t(
                __CLASS__ . '.DATE_LOCKED',
                'Datum se ne može mijenjati jer postoje odabiri za ovaj meni.'
            ));
        }
        if ($this->isInDB() && $this->isChanged('MealType') && $this->Selections()->exists()) {
            $result->addFieldError('MealType', _t(
                __CLASS__ . '.MEAL_LOCKED',
                'Obrok se ne može mijenjati jer postoje odabiri za ovaj meni.'
            ));
        }

        return $result;
    }

    public function getTitle(): string
    {
        return (string) $this->OptionName;
    }

    public function getMealTypeLabel(): string
    {
        return self::mealTypeOptions()[$this->MealType] ?? (string) $this->MealType;
    }

    public function getUnsuitableLabel(): string
    {
        return implode(', ', $this->NotSuitableFor()->column('Title'));
    }

    public function getSelectionCount(): int
    {
        return $this->Selections()->count();
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
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member)
            && (!$this->isInDB() || !$this->Selections()->exists());
    }
}
