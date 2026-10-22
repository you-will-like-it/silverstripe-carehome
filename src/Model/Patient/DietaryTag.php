<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Patient;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Optional diet marker (e.g. "Dijabetes", "Miksana hrana").
 * Patient.DietaryTags <-> WeeklyMenuItem.NotSuitableFor drive menu filtering.
 * Unused tags = no filtering, every menu option is offered.
 *
 * @property string $Title
 * @property string $Description
 * @method ManyManyList<Patient> Patients()
 * @method ManyManyList<WeeklyMenuItem> UnsuitableMenuItems()
 */
class DietaryTag extends DataObject
{
    private static string $table_name = 'CareHome_DietaryTag';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Description' => 'Text',
    ];

    private static array $belongs_many_many = [
        'Patients' => Patient::class . '.DietaryTags',
        'UnsuitableMenuItems' => WeeklyMenuItem::class . '.NotSuitableFor',
    ];

    private static array $indexes = [
        'Title' => ['type' => 'unique', 'columns' => ['Title']],
    ];

    private static string $default_sort = '"Title" ASC';

    private static array $summary_fields = ['Title', 'Description'];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Dijetetska oznaka');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Dijetetske oznake');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Description' => _t(__CLASS__ . '.DESCRIPTION', 'Opis'),
        ]);
    }

    public function getCMSFields(): \SilverStripe\Forms\FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Patients', 'UnsuitableMenuItems']);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(__CLASS__ . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }

        return $result;
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
