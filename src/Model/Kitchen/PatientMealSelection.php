<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Kitchen;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * A resident's choice for one meal of one day. MealType is denormalised from MenuItem
 * to enforce "one choice per meal per day" with a unique index.
 *
 * @property string $SelectedDate
 * @property string $MealType
 * @property int $PatientID
 * @property int $MenuItemID
 * @property int $RecordedByID
 * @method Patient Patient()
 * @method WeeklyMenuItem MenuItem()
 * @method Member RecordedBy()
 */
class PatientMealSelection extends DataObject
{
    private static string $table_name = 'CareHome_PatientMealSelection';

    private static array $db = [
        'SelectedDate' => 'Date',
        'MealType' => 'Varchar(10)',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'MenuItem' => WeeklyMenuItem::class,
        'RecordedBy' => Member::class,
    ];

    private static array $indexes = [
        'PatientDateMeal' => ['type' => 'unique', 'columns' => ['PatientID', 'SelectedDate', 'MealType']],
        'DateMeal' => ['type' => 'index', 'columns' => ['SelectedDate', 'MealType']],
    ];

    private static string $default_sort = '"SelectedDate" DESC, "MealType" ASC';

    private static array $summary_fields = [
        'SelectedDate.Nice',
        'MealTypeLabel',
        'MenuItem.OptionName',
        'RecordedBy.Name',
        'LastEdited.Nice',
    ];

    private static array $casting = [
        'MealTypeLabel' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Izbor obroka');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Izbori obroka');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'SelectedDate.Nice' => _t(__CLASS__ . '.DATE', 'Datum'),
            'MealTypeLabel' => _t(__CLASS__ . '.MEAL', 'Obrok'),
            'MenuItem.OptionName' => _t(__CLASS__ . '.MENU', 'Meni'),
            'RecordedBy.Name' => _t(__CLASS__ . '.RECORDEDBY', 'Evidentirao'),
            'LastEdited.Nice' => _t(__CLASS__ . '.WHEN', 'Vrijeme unosa'),
        ]);
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->MenuItemID) {
            $item = $this->MenuItem();
            $this->MealType = (string) $item->MealType;
            $this->SelectedDate = (string) $item->MenuDate;
        }
    }

    public function getMealTypeLabel(): string
    {
        return WeeklyMenuItem::mealTypeOptions()[$this->MealType] ?? (string) $this->MealType;
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
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }
}
