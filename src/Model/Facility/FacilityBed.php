<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Facility;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareHome\Control\Admin\QrPrintController;
use YouWillLikeIT\CareHome\Model\Patient\BedAssignment;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\QrCodeService;

/**
 * A bed. Occupancy is derived from Patient.BedLock (single source of truth),
 * never stored on the bed itself.
 *
 * @property string $BedLabel
 * @property string $UniqueHash
 * @property int $RoomID
 * @method FacilityRoom Room()
 * @method HasManyList<BedAssignment> Assignments()
 */
class FacilityBed extends DataObject
{
    private static string $table_name = 'CareHome_FacilityBed';

    private static array $db = [
        'BedLabel' => 'Varchar(150)',
        'UniqueHash' => 'Varchar(64)',
    ];

    private static array $has_one = [
        'Room' => FacilityRoom::class,
    ];

    private static array $has_many = [
        'Assignments' => BedAssignment::class . '.Bed',
    ];

    private static array $indexes = [
        'UniqueHash' => ['type' => 'unique', 'columns' => ['UniqueHash']],
    ];

    private static string $default_sort = '"BedLabel" ASC';

    private static array $summary_fields = [
        'BedLabel',
        'OccupancyLabel',
    ];

    private static array $casting = [
        'IsOccupied' => 'Boolean',
        'OccupancyLabel' => 'Varchar',
        'FullTitle' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Krevet');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Kreveti');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'BedLabel' => _t(__CLASS__ . '.BEDLABEL', 'Oznaka kreveta'),
            'UniqueHash' => _t(__CLASS__ . '.UNIQUEHASH', 'QR identifikator'),
            'Room' => _t(__CLASS__ . '.ROOM', 'Soba'),
            'OccupancyLabel' => _t(__CLASS__ . '.OCCUPANCY', 'Zauzetost'),
        ]);
    }

    public function getTitle(): string
    {
        return (string) $this->BedLabel;
    }

    public function getFullTitle(): string
    {
        $room = $this->Room();

        return ($room->exists() ? $room->getFullTitle() . ' › ' : '') . $this->BedLabel;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['RoomID', 'UniqueHash', 'Assignments']);

        if ($this->isInDB()) {
            $patient = $this->CurrentPatient();
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('OccupancyInfo', $this->fieldLabel('OccupancyLabel'), $this->getOccupancyLabel()),
                ReadonlyField::create('UniqueHashRO', $this->fieldLabel('UniqueHash'), $this->UniqueHash),
                LiteralField::create('QrPreview', sprintf(
                    '<div class="form-group field"><div style="max-width:220px">%s</div>'
                    . '<p><code>%s</code></p>'
                    . '<p><a class="btn btn-outline-secondary font-icon-print" target="_blank" rel="noopener" href="%s">%s</a></p></div>',
                    QrCodeService::singleton()->svg($this->getPortalURL()),
                    htmlspecialchars($this->getPortalURL()),
                    htmlspecialchars(QrPrintController::linkFor('bed', (int) $this->ID)),
                    _t(__CLASS__ . '.PRINT_QR', 'Štampaj QR kod')
                )),
            ]);
            if ($patient) {
                $fields->addFieldToTab('Root.Main', ReadonlyField::create(
                    'PatientInfo',
                    _t(__CLASS__ . '.CURRENT_PATIENT', 'Trenutni štićenik'),
                    $patient->getFullName()
                ));
            }
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->BedLabel) === '') {
            $result->addFieldError('BedLabel', _t(__CLASS__ . '.LABEL_REQUIRED', 'Oznaka kreveta je obavezna.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->UniqueHash) {
            $this->UniqueHash = self::generateHash();
        }
    }

    public static function generateHash(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function getPortalURL(): string
    {
        return QrCodeService::singleton()->bedURL((string) $this->UniqueHash);
    }

    /**
     * Active resident currently holding this bed (absent residents still hold it).
     */
    public function CurrentPatient(): ?Patient
    {
        if (!$this->isInDB()) {
            return null;
        }

        /** @var Patient|null $patient */
        $patient = Patient::get()->filter('BedLock', (string) $this->ID)->first();

        return $patient;
    }

    public function getIsOccupied(): bool
    {
        return $this->CurrentPatient() !== null;
    }

    public function getOccupancyLabel(): string
    {
        $patient = $this->CurrentPatient();
        if (!$patient) {
            return _t(__CLASS__ . '.FREE', 'Slobodan');
        }

        return $patient->IsTemporarilyAbsent
            ? _t(__CLASS__ . '.OCCUPIED_ABSENT', 'Zauzet ({name}, privremeno odsutan)', ['name' => $patient->getFullName()])
            : _t(__CLASS__ . '.OCCUPIED', 'Zauzet ({name})', ['name' => $patient->getFullName()]);
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
            && (!$this->isInDB() || (!$this->getIsOccupied() && $this->Assignments()->count() === 0));
    }
}
