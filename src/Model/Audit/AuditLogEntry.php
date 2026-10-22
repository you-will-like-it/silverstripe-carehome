<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Audit;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Immutable audit trail row. Never editable or deletable through the ORM permission layer.
 *
 * @property string $Action
 * @property string $ObjectClass
 * @property int $ObjectID
 * @property string $ObjectTitle
 * @property string $Changes JSON {field: {before, after}}
 * @property string $ActorName
 * @property string $Channel cms|portal|kitchen|cli|system
 * @property string $IPAddress
 * @property int $ActorID
 * @method Member Actor()
 */
class AuditLogEntry extends DataObject
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_UPDATE = 'update';
    public const string ACTION_DELETE = 'delete';
    public const string ACTION_LOGIN = 'login';
    public const string ACTION_LOGIN_FAILED = 'login_failed';
    public const string ACTION_LOGOUT = 'logout';
    public const string ACTION_EVENT = 'event';

    private static string $table_name = 'CareHome_AuditLogEntry';

    private static array $db = [
        'Action' => 'Varchar(20)',
        'ObjectClass' => 'Varchar(255)',
        'ObjectID' => 'Int',
        'ObjectTitle' => 'Varchar(255)',
        'Changes' => 'Text',
        'ActorName' => 'Varchar(255)',
        'Channel' => 'Varchar(20)',
        'IPAddress' => 'Varchar(45)',
    ];

    private static array $has_one = [
        'Actor' => Member::class,
    ];

    private static array $indexes = [
        'Object' => ['type' => 'index', 'columns' => ['ObjectClass', 'ObjectID']],
        'Created' => true,
        'Action' => true,
    ];

    private static string $default_sort = '"Created" DESC, "ID" DESC';

    private static array $summary_fields = [
        'Created.Nice',
        'ActionLabel',
        'ObjectTypeLabel',
        'ObjectTitle',
        'ChangedFieldNames',
        'ActorName',
        'Channel',
    ];

    private static array $searchable_fields = [
        'Action',
        'ObjectClass',
        'ObjectTitle',
        'ActorName',
        'Channel',
    ];

    private static array $casting = [
        'ActionLabel' => 'Varchar',
        'ObjectTypeLabel' => 'Varchar',
        'ChangedFieldNames' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Audit zapis');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Audit log');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Created' => _t(__CLASS__ . '.CREATED', 'Vrijeme'),
            'Created.Nice' => _t(__CLASS__ . '.CREATED', 'Vrijeme'),
            'Action' => _t(__CLASS__ . '.ACTION', 'Akcija'),
            'ActionLabel' => _t(__CLASS__ . '.ACTION', 'Akcija'),
            'ObjectClass' => _t(__CLASS__ . '.OBJECTCLASS', 'Tip zapisa'),
            'ObjectTypeLabel' => _t(__CLASS__ . '.OBJECTCLASS', 'Tip zapisa'),
            'ObjectID' => 'ID',
            'ObjectTitle' => _t(__CLASS__ . '.OBJECTTITLE', 'Zapis'),
            'ChangedFieldNames' => _t(__CLASS__ . '.FIELDS', 'Polja'),
            'ActorName' => _t(__CLASS__ . '.ACTOR', 'Korisnik'),
            'Channel' => _t(__CLASS__ . '.CHANNEL', 'Kanal'),
            'IPAddress' => _t(__CLASS__ . '.IP', 'IP adresa'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(\SilverStripe\Forms\TabSet::create('Root', \SilverStripe\Forms\Tab::create('Main')));
        foreach (['ActionLabel', 'ObjectTypeLabel', 'ObjectID', 'ObjectTitle', 'ActorName', 'Channel', 'IPAddress'] as $name) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create($name . 'RO', $this->fieldLabel($name), (string) $this->{$name}));
        }
        $fields->addFieldToTab('Root.Main', ReadonlyField::create('CreatedRO', $this->fieldLabel('Created'), $this->dbObject('Created')->Nice()));

        $rows = '';
        foreach ($this->getDecodedChanges() as $field => $change) {
            $rows .= sprintf(
                '<tr><th>%s</th><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string) $field),
                nl2br(htmlspecialchars(self::stringify($change['before'] ?? null))),
                nl2br(htmlspecialchars(self::stringify($change['after'] ?? null)))
            );
        }
        if ($rows !== '') {
            $fields->addFieldToTab('Root.Main', LiteralField::create('ChangesTable', sprintf(
                '<div class="form-group"><table class="table table-sm"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table></div>',
                _t(__CLASS__ . '.FIELD', 'Polje'),
                _t(__CLASS__ . '.BEFORE', 'Prije'),
                _t(__CLASS__ . '.AFTER', 'Poslije'),
                $rows
            )));
        }

        return $fields;
    }

    /** @return array<string,array{before:mixed,after:mixed}> */
    public function getDecodedChanges(): array
    {
        $decoded = json_decode((string) $this->Changes, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getChangedFieldNames(): string
    {
        return implode(', ', array_keys($this->getDecodedChanges()));
    }

    public function getActionLabel(): string
    {
        return match ($this->Action) {
            self::ACTION_CREATE => _t(__CLASS__ . '.A_CREATE', 'Kreiranje'),
            self::ACTION_UPDATE => _t(__CLASS__ . '.A_UPDATE', 'Izmjena'),
            self::ACTION_DELETE => _t(__CLASS__ . '.A_DELETE', 'Brisanje'),
            self::ACTION_LOGIN => _t(__CLASS__ . '.A_LOGIN', 'Prijava'),
            self::ACTION_LOGIN_FAILED => _t(__CLASS__ . '.A_LOGIN_FAILED', 'Neuspjela prijava'),
            self::ACTION_LOGOUT => _t(__CLASS__ . '.A_LOGOUT', 'Odjava'),
            default => (string) $this->Action,
        };
    }

    public function getObjectTypeLabel(): string
    {
        if (!$this->ObjectClass || !class_exists($this->ObjectClass)) {
            return (string) $this->ObjectClass;
        }

        return DataObject::singleton($this->ObjectClass)->i18n_singular_name();
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function canView($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::AUDIT, $member);
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
        return false;
    }
}
