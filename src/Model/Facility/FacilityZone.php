<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Facility;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Building / wing / entrance / floor. Recursive via Parent.
 *
 * @property string $Title
 * @property string $Description
 * @property int $Sort
 * @property int $ParentID
 * @method FacilityZone Parent()
 * @method HasManyList<FacilityZone> Children()
 * @method HasManyList<FacilityRoom> Rooms()
 * @method ManyManyThroughList<Member> Workers()
 */
class FacilityZone extends DataObject
{
    private static string $table_name = 'CareHome_FacilityZone';

    private static array $db = [
        'Title' => 'Varchar(150)',
        'Description' => 'Text',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Parent' => FacilityZone::class,
    ];

    private static array $many_many = [
        'Workers' => [
            'through' => MemberZoneAssignment::class,
            'from' => 'Zone',
            'to' => 'Member',
        ],
    ];

    private static array $has_many = [
        'Children' => FacilityZone::class . '.Parent',
        'Rooms' => FacilityRoom::class . '.Zone',
        'WorkerLinks' => MemberZoneAssignment::class . '.Zone',
    ];

    private static array $cascade_deletes = [
        'WorkerLinks',
    ];

    private static array $indexes = [
        'Sort' => true,
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    /** Labels come from fieldLabels() so they stay translatable. */
    private static array $summary_fields = [
        'Title',
        'Description',
        'ChildCount',
        'RoomCount',
    ];

    private static array $casting = [
        'ChildCount' => 'Int',
        'RoomCount' => 'Int',
        'Breadcrumb' => 'Varchar',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Description' => _t(__CLASS__ . '.DESCRIPTION', 'Opis'),
            'Parent' => _t(__CLASS__ . '.PARENT', 'Nadređena zona'),
            'Children' => _t(__CLASS__ . '.CHILDREN', 'Podzone'),
            'Rooms' => _t(__CLASS__ . '.ROOMS', 'Sobe'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'ChildCount' => _t(__CLASS__ . '.CHILDREN', 'Podzone'),
            'RoomCount' => _t(__CLASS__ . '.ROOMS', 'Sobe'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Zona');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Zone');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ParentID', 'Children', 'Rooms', 'Workers', 'WorkerLinks']);

        $fields->addFieldToTab('Root.Main', $this->buildParentField(), 'Sort');

        if ($this->isInDB()) {
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'BreadcrumbInfo',
                '<p class="message notice">' . htmlspecialchars($this->getBreadcrumb()) . '</p>'
            ), 'Title');

            $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.CHILDREN', 'Podzone'), GridField::create(
                'Children',
                _t(__CLASS__ . '.CHILDREN', 'Podzone'),
                $this->Children(),
                GridFieldConfig_RecordEditor::create()
            ));

            $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.ROOMS', 'Sobe'), GridField::create(
                'Rooms',
                _t(__CLASS__ . '.ROOMS', 'Sobe'),
                $this->Rooms(),
                GridFieldConfig_RecordEditor::create()
            ));
        }

        return $fields;
    }

    private function buildParentField(): DropdownField
    {
        $excluded = $this->isInDB()
            ? ZoneScope::singleton()->descendantIds([(int) $this->ID])
            : [];

        $options = [];
        foreach (FacilityZone::get() as $zone) {
            if (in_array((int) $zone->ID, $excluded, true)) {
                continue;
            }
            $options[$zone->ID] = $zone->getBreadcrumb();
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return DropdownField::create('ParentID', $this->fieldLabel('Parent'), $options)
            ->setEmptyString(_t(__CLASS__ . '.TOP_LEVEL', '— najviši nivo —'));
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(__CLASS__ . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }

        if ($this->isInDB() && $this->ParentID) {
            $descendants = ZoneScope::singleton()->descendantIds([(int) $this->ID]);
            if (in_array((int) $this->ParentID, $descendants, true)) {
                $result->addFieldError('ParentID', _t(
                    __CLASS__ . '.PARENT_CYCLE',
                    'Zona ne može biti podređena sama sebi ili svojoj podzoni.'
                ));
            }
        }

        return $result;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        ZoneScope::singleton()->reset();
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        ZoneScope::singleton()->reset();
    }

    public function getBreadcrumb(string $separator = ' › '): string
    {
        $parts = [];
        $seen = [];
        $zone = $this;
        while ($zone && $zone->exists() && !isset($seen[$zone->ID])) {
            $seen[$zone->ID] = true;
            array_unshift($parts, (string) $zone->Title);
            $zone = $zone->ParentID ? $zone->Parent() : null;
        }

        return implode($separator, $parts);
    }

    public function getChildCount(): int
    {
        return $this->Children()->count();
    }

    public function getRoomCount(): int
    {
        return $this->Rooms()->count();
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
     * Zones holding rooms or sub-zones cannot be deleted (prevents orphaned beds/residents).
     */
    public function canDelete($member = null): bool
    {
        if (!CareHomePermissions::check(CareHomePermissions::ADMIN, $member)) {
            return false;
        }

        return !$this->isInDB() || ($this->Children()->count() === 0 && $this->Rooms()->count() === 0);
    }
}
