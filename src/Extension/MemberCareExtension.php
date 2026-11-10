<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Extension;

use SilverStripe\Admin\SecurityAdmin;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareHome\Admin\AuditLogAdmin;
use YouWillLikeIT\CareHome\Admin\CareTaskAdmin;
use YouWillLikeIT\CareHome\Admin\FacilityAdmin;
use YouWillLikeIT\CareHome\Admin\KitchenAdmin;
use YouWillLikeIT\CareHome\Admin\PatientAdmin;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Facility\MemberZoneAssignment;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Security\PinHasher;

/**
 * Worker data on Member: role, PIN (HMAC lookup) and assigned zones.
 * WorkerRole is the single admin-facing switch; group membership is synchronised from it.
 *
 * @property string $WorkerRole
 * @property string|null $WorkerPinLookup
 * @property bool $PortalEnabled
 * @method ManyManyThroughList<FacilityZone> AssignedZones()
 * @method HasManyList<MemberZoneAssignment> ZoneLinks()
 * @extends Extension<Member>
 */
class MemberCareExtension extends Extension
{
    public const string ROLE_NONE = 'None';
    public const string ROLE_ADMIN = 'Administrator';
    public const string ROLE_CAREWORKER = 'CareWorker';
    public const string ROLE_KITCHEN = 'Kitchen';

    private static array $db = [
        'WorkerRole' => "Enum('None,Administrator,CareWorker,Kitchen','None')",
        'WorkerPinLookup' => 'Varchar(64)',
        'PortalEnabled' => 'Boolean(1)',
    ];

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'WorkerRole' => self::ROLE_NONE,
        'PortalEnabled' => true,
    ];

    private static array $many_many = [
        'AssignedZones' => [
            'through' => MemberZoneAssignment::class,
            'from' => 'Member',
            'to' => 'Zone',
        ],
    ];

    private static array $has_many = [
        'ZoneLinks' => MemberZoneAssignment::class . '.Member',
    ];

    private static array $cascade_deletes = [
        'ZoneLinks',
    ];

    private static array $indexes = [
        'WorkerPinLookup' => ['type' => 'unique', 'columns' => ['WorkerPinLookup']],
    ];

    /** @return array<string,string> */
    public static function roleOptions(): array
    {
        return [
            self::ROLE_NONE => _t(__CLASS__ . '.ROLE_NONE', '— nije radnik doma —'),
            self::ROLE_ADMIN => _t(__CLASS__ . '.ROLE_ADMIN', 'Administrator'),
            self::ROLE_CAREWORKER => _t(__CLASS__ . '.ROLE_CAREWORKER', 'Njegovatelj'),
            self::ROLE_KITCHEN => _t(__CLASS__ . '.ROLE_KITCHEN', 'Kuhinja'),
        ];
    }

    /** @return array<string,string> role => group code */
    public static function roleGroups(): array
    {
        return [
            self::ROLE_ADMIN => CareHomePermissions::GROUP_ADMINS,
            self::ROLE_CAREWORKER => CareHomePermissions::GROUP_WORKERS,
            self::ROLE_KITCHEN => CareHomePermissions::GROUP_KITCHEN,
        ];
    }

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['WorkerRole', 'WorkerPinLookup', 'PortalEnabled', 'AssignedZones', 'ZoneLinks']);

        $owner = $this->getOwner();
        $tab = 'Root.' . _t(__CLASS__ . '.TAB', 'Dom za njegu');

        $zones = [];
        foreach (FacilityZone::get() as $zone) {
            $zones[$zone->ID] = $zone->getBreadcrumb();
        }
        asort($zones, SORT_NATURAL | SORT_FLAG_CASE);

        $fields->addFieldsToTab($tab, [
            DropdownField::create('WorkerRole', _t(__CLASS__ . '.ROLE', 'Uloga u domu'), self::roleOptions())
                ->setDescription(_t(__CLASS__ . '.ROLE_DESC', 'Uloga automatski određuje grupu i prava.')),
            CheckboxField::create('PortalEnabled', _t(__CLASS__ . '.PORTAL_ENABLED', 'PIN prijava dozvoljena')),
            ReadonlyField::create('PinStatus', _t(__CLASS__ . '.PIN_STATUS', 'PIN'), $owner->WorkerPinLookup
                ? _t(__CLASS__ . '.PIN_SET', 'postavljen')
                : _t(__CLASS__ . '.PIN_NOT_SET', 'nije postavljen')),
            PasswordField::create('NewWorkerPin', _t(__CLASS__ . '.NEW_PIN', 'Novi PIN'))
                ->setAttribute('inputmode', 'numeric')
                ->setAttribute('autocomplete', 'new-password')
                ->setDescription(_t(
                    __CLASS__ . '.NEW_PIN_DESC',
                    '4–6 cifara, jedinstven po radniku. Prazno = bez promjene.'
                )),
            CheckboxField::create('RemoveWorkerPin', _t(__CLASS__ . '.REMOVE_PIN', 'Ukloni PIN')),
            ListboxField::create('AssignedZones', _t(__CLASS__ . '.ZONES', 'Dodijeljene zone'), $zones)
                ->setDescription(_t(
                    __CLASS__ . '.ZONES_DESC',
                    'Radnik vidi štićenike u ovim zonama i svim njihovim podzonama.'
                )),
        ]);
    }

    protected function updateValidate(ValidationResult $result): void
    {
        $owner = $this->getOwner();
        $pin = trim((string) $owner->getField('NewWorkerPin'));

        // empty = not a care worker (e.g. default admin created during db:build)
        $role = (string) $owner->WorkerRole;
        if ($role !== '' && !array_key_exists($role, self::roleOptions())) {
            $result->addFieldError('WorkerRole', _t(__CLASS__ . '.INVALID_ROLE', 'Nepoznata uloga.'));
        }

        if ($pin === '') {
            return;
        }

        $hasher = PinHasher::singleton();
        if (!$hasher->isConfigured()) {
            $result->addFieldError('NewWorkerPin', _t(
                __CLASS__ . '.PEPPER_MISSING',
                'Server nije konfigurisan: CAREHOME_PIN_PEPPER nedostaje u .env.'
            ));

            return;
        }
        if (!$hasher->isValidFormat($pin)) {
            $result->addFieldError('NewWorkerPin', _t(__CLASS__ . '.PIN_FORMAT', 'PIN mora imati 4–6 cifara.'));

            return;
        }
        if ($hasher->isTrivial($pin)) {
            $result->addFieldError('NewWorkerPin', _t(__CLASS__ . '.PIN_TRIVIAL', 'PIN je prejednostavan (npr. 1111, 1234).'));

            return;
        }
        $taken = Member::get()
            ->filter('WorkerPinLookup', $hasher->lookup($pin))
            ->exclude('ID', (int) $owner->ID)
            ->exists();
        if ($taken) {
            $result->addFieldError('NewWorkerPin', _t(__CLASS__ . '.PIN_TAKEN', 'Ovaj PIN je već dodijeljen. Odaberite drugi.'));
        }
    }

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if ((string) $owner->WorkerRole === '') {
            $owner->WorkerRole = self::ROLE_NONE;
        }
        $pin = trim((string) $owner->getField('NewWorkerPin'));

        if ($pin !== '') {
            $owner->WorkerPinLookup = PinHasher::singleton()->lookup($pin);
        } elseif ($owner->getField('RemoveWorkerPin')) {
            $owner->WorkerPinLookup = null;
        }

        // transient form values must never linger on the record
        $owner->setField('NewWorkerPin', null);
        $owner->setField('RemoveWorkerPin', null);
    }

    protected function onAfterWrite(): void
    {
        $owner = $this->getOwner();
        if (!$owner->isChanged('WorkerRole', DataObject::CHANGE_VALUE)) {
            return;
        }

        $target = self::roleGroups()[$owner->WorkerRole] ?? null;
        foreach (self::roleGroups() as $code) {
            $group = Group::get()->filter('Code', $code)->first();
            if (!$group) {
                continue;
            }
            if ($code === $target) {
                $owner->Groups()->add($group);
            } else {
                $owner->Groups()->remove($group);
            }
        }
    }

    /**
     * Creates the three module groups with their permissions (idempotent).
     */
    protected function requireDefaultRecords(): void
    {
        $definitions = [
            CareHomePermissions::GROUP_ADMINS => [
                'title' => _t(__CLASS__ . '.GROUP_ADMINS', 'Dom – Administratori'),
                'permissions' => [
                    CareHomePermissions::ADMIN,
                    CareHomePermissions::REPORTS,
                    CareHomePermissions::PORTAL,
                    CareHomePermissions::KITCHEN,
                    CareHomePermissions::AUDIT,
                    'CMS_ACCESS_' . FacilityAdmin::class,
                    'CMS_ACCESS_' . PatientAdmin::class,
                    'CMS_ACCESS_' . CareTaskAdmin::class,
                    'CMS_ACCESS_' . KitchenAdmin::class,
                    'CMS_ACCESS_' . AuditLogAdmin::class,
                    'CMS_ACCESS_' . SecurityAdmin::class,
                ],
            ],
            CareHomePermissions::GROUP_WORKERS => [
                'title' => _t(__CLASS__ . '.GROUP_WORKERS', 'Dom – Njegovatelji'),
                'permissions' => [CareHomePermissions::PORTAL],
            ],
            CareHomePermissions::GROUP_KITCHEN => [
                'title' => _t(__CLASS__ . '.GROUP_KITCHEN', 'Dom – Kuhinja'),
                'permissions' => [CareHomePermissions::KITCHEN],
            ],
        ];

        foreach ($definitions as $code => $definition) {
            $group = Group::get()->filter('Code', $code)->first();
            if (!$group) {
                $group = Group::create();
                $group->Code = $code;
                $group->Title = $definition['title'];
                $group->write();
            }
            foreach ($definition['permissions'] as $permission) {
                if (!Permission::get()->filter(['GroupID' => $group->ID, 'Code' => $permission])->exists()) {
                    Permission::grant($group->ID, $permission);
                }
            }
        }
    }

    public function isPortalWorker(): bool
    {
        $owner = $this->getOwner();

        return (bool) $owner->PortalEnabled
            && $owner->WorkerPinLookup
            && $owner->WorkerRole !== self::ROLE_NONE;
    }
}
