<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Facility;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareHome\Control\Admin\QrPrintController;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Room inside a zone. Edited only through FacilityZone's nested GridField.
 *
 * @property string $RoomNumber
 * @property string $FloorInfo
 * @property int $ZoneID
 * @method FacilityZone Zone()
 * @method HasManyList<FacilityBed> Beds()
 */
class FacilityRoom extends DataObject
{
    private static string $table_name = 'CareHome_FacilityRoom';

    private static array $db = [
        'RoomNumber' => 'Varchar(30)',
        'FloorInfo' => 'Varchar(100)',
    ];

    private static array $has_one = [
        'Zone' => FacilityZone::class,
    ];

    private static array $has_many = [
        'Beds' => FacilityBed::class . '.Room',
    ];

    private static array $indexes = [
        'ZoneRoom' => ['type' => 'unique', 'columns' => ['ZoneID', 'RoomNumber']],
    ];

    private static string $default_sort = '"RoomNumber" ASC';

    private static array $summary_fields = [
        'RoomNumber',
        'FloorInfo',
        'BedCount',
        'FreeBedCount',
    ];

    private static array $casting = [
        'BedCount' => 'Int',
        'FreeBedCount' => 'Int',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Soba');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Sobe');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'RoomNumber' => _t(__CLASS__ . '.ROOMNUMBER', 'Broj sobe'),
            'FloorInfo' => _t(__CLASS__ . '.FLOORINFO', 'Sprat / napomena'),
            'Zone' => _t(__CLASS__ . '.ZONE', 'Zona'),
            'Beds' => _t(__CLASS__ . '.BEDS', 'Kreveti'),
            'BedCount' => _t(__CLASS__ . '.BEDS', 'Kreveti'),
            'FreeBedCount' => _t(__CLASS__ . '.FREEBEDS', 'Slobodni'),
        ]);
    }

    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE_FORMAT', 'Soba {number}', ['number' => $this->RoomNumber]);
    }

    public function getFullTitle(): string
    {
        $zone = $this->Zone();

        return ($zone->exists() ? $zone->getBreadcrumb() . ' › ' : '') . $this->getTitle();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ZoneID', 'Beds']);

        if ($this->isInDB()) {
            $fields->addFieldToTab('Root.Main', GridField::create(
                'Beds',
                $this->fieldLabel('Beds'),
                $this->Beds(),
                GridFieldConfig_RecordEditor::create()
            ));

            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'QrPrintRoom',
                sprintf(
                    '<p><a class="btn btn-outline-secondary font-icon-print" target="_blank" rel="noopener" href="%s">%s</a></p>',
                    htmlspecialchars(QrPrintController::linkFor('room', (int) $this->ID)),
                    _t(__CLASS__ . '.PRINT_QR', 'Štampaj QR kodove svih kreveta u sobi')
                )
            ));
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->RoomNumber) === '') {
            $result->addFieldError('RoomNumber', _t(__CLASS__ . '.NUMBER_REQUIRED', 'Broj sobe je obavezan.'));
        }

        return $result;
    }

    public function getBedCount(): int
    {
        return $this->Beds()->count();
    }

    public function getFreeBedCount(): int
    {
        $free = 0;
        foreach ($this->Beds() as $bed) {
            if (!$bed->getIsOccupied()) {
                $free++;
            }
        }

        return $free;
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
            && (!$this->isInDB() || $this->Beds()->count() === 0);
    }
}
