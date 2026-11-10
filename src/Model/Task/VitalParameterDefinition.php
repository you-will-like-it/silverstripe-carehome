<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Dynamic vital parameter definition. Only superadmins (core ADMIN permission) may
 * create/change/delete definitions; CareHome admins only select them on templates.
 *
 * Min/Max = plausibility limits (input outside is rejected), NormalMin/NormalMax = reference range
 * (outside → flagged abnormal). 0/0 means "not defined".
 *
 * @property string $Title
 * @property string $Code
 * @property string $Unit
 * @property string $ValueType
 * @property float $MinValue
 * @property float $MaxValue
 * @property float $NormalMin
 * @property float $NormalMax
 * @property int $SortOrder
 * @property bool $IsActive
 * @method HasManyList<VitalReading> Readings()
 */
class VitalParameterDefinition extends DataObject
{
    public const string TYPE_INTEGER = 'Integer';
    public const string TYPE_DECIMAL = 'Decimal';

    private static string $table_name = 'CareHome_VitalParameterDefinition';

    /** DB-level Enum/Boolean defaults are not applied to new PHP objects in SS6; set them explicitly. */
    private static array $defaults = [
        'ValueType' => self::TYPE_DECIMAL,
        'IsActive' => true,
    ];

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Code' => 'Varchar(30)',
        'Unit' => 'Varchar(20)',
        'ValueType' => "Enum('Integer,Decimal','Decimal')",
        'MinValue' => 'Decimal(10,2)',
        'MaxValue' => 'Decimal(10,2)',
        'NormalMin' => 'Decimal(10,2)',
        'NormalMax' => 'Decimal(10,2)',
        'SortOrder' => 'Int',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $has_many = [
        'Readings' => VitalReading::class . '.Definition',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static string $default_sort = '"SortOrder" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Title',
        'Code',
        'Unit',
        'RangeLabel',
        'IsActive.Nice',
    ];

    private static array $casting = [
        'LabelWithUnit' => 'Varchar',
        'RangeLabel' => 'Varchar',
    ];

    /**
     * Seeded on first dev/build when the table is empty.
     *
     * @var list<array<string,mixed>>
     */
    private static array $default_definitions = [
        ['Code' => 'BP_SYS', 'Title' => 'Krvni pritisak – sistolni', 'Unit' => 'mmHg', 'ValueType' => 'Integer', 'MinValue' => 50, 'MaxValue' => 260, 'NormalMin' => 90, 'NormalMax' => 140],
        ['Code' => 'BP_DIA', 'Title' => 'Krvni pritisak – dijastolni', 'Unit' => 'mmHg', 'ValueType' => 'Integer', 'MinValue' => 30, 'MaxValue' => 160, 'NormalMin' => 60, 'NormalMax' => 90],
        ['Code' => 'PULSE', 'Title' => 'Puls', 'Unit' => '/min', 'ValueType' => 'Integer', 'MinValue' => 20, 'MaxValue' => 250, 'NormalMin' => 60, 'NormalMax' => 100],
        ['Code' => 'TEMP', 'Title' => 'Tjelesna temperatura', 'Unit' => '°C', 'ValueType' => 'Decimal', 'MinValue' => 30, 'MaxValue' => 44, 'NormalMin' => 36, 'NormalMax' => 37.5],
        ['Code' => 'SPO2', 'Title' => 'Saturacija kisikom', 'Unit' => '%', 'ValueType' => 'Integer', 'MinValue' => 50, 'MaxValue' => 100, 'NormalMin' => 94, 'NormalMax' => 100],
        ['Code' => 'GLUCOSE', 'Title' => 'Šećer u krvi', 'Unit' => 'mmol/L', 'ValueType' => 'Decimal', 'MinValue' => 1, 'MaxValue' => 40, 'NormalMin' => 4, 'NormalMax' => 10],
        ['Code' => 'WEIGHT', 'Title' => 'Tjelesna težina', 'Unit' => 'kg', 'ValueType' => 'Decimal', 'MinValue' => 20, 'MaxValue' => 250, 'NormalMin' => 0, 'NormalMax' => 0],
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Vitalni parametar');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Vitalni parametri');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Code' => _t(__CLASS__ . '.CODE', 'Šifra'),
            'Unit' => _t(__CLASS__ . '.UNIT', 'Jedinica'),
            'ValueType' => _t(__CLASS__ . '.VALUETYPE', 'Tip vrijednosti'),
            'MinValue' => _t(__CLASS__ . '.MIN', 'Najmanja moguća vrijednost'),
            'MaxValue' => _t(__CLASS__ . '.MAX', 'Najveća moguća vrijednost'),
            'NormalMin' => _t(__CLASS__ . '.NORMALMIN', 'Referentni opseg – od'),
            'NormalMax' => _t(__CLASS__ . '.NORMALMAX', 'Referentni opseg – do'),
            'SortOrder' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'IsActive' => _t(__CLASS__ . '.ISACTIVE', 'Aktivan'),
            'IsActive.Nice' => _t(__CLASS__ . '.ISACTIVE', 'Aktivan'),
            'RangeLabel' => _t(__CLASS__ . '.RANGE', 'Referentni opseg'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('Readings');
        $fields->dataFieldByName('MinValue')?->setDescription(_t(
            __CLASS__ . '.MINMAX_DESC',
            'Unos izvan granica se odbija (zaštita od greške u kucanju). 0 i 0 = bez provjere.'
        ));
        $fields->dataFieldByName('NormalMin')?->setDescription(_t(
            __CLASS__ . '.NORMAL_DESC',
            'Vrijednosti izvan opsega su označene u portalu i PDF-u. 0 i 0 = bez opsega.'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(__CLASS__ . '.REQ_TITLE', 'Naziv je obavezan.'));
        }
        if (!preg_match('/^[A-Z0-9_]{2,30}$/', (string) $this->Code)) {
            $result->addFieldError('Code', _t(__CLASS__ . '.INVALID_CODE', 'Šifra: 2–30 znakova A–Z, 0–9, _.'));
        }
        if ($this->hasPlausibilityRange() && (float) $this->MinValue >= (float) $this->MaxValue) {
            $result->addFieldError('MaxValue', _t(__CLASS__ . '.INVALID_RANGE', 'Najveća vrijednost mora biti veća od najmanje.'));
        }
        if ($this->hasNormalRange() && (float) $this->NormalMin >= (float) $this->NormalMax) {
            $result->addFieldError('NormalMax', _t(__CLASS__ . '.INVALID_RANGE', 'Najveća vrijednost mora biti veća od najmanje.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->Code = strtoupper(trim((string) $this->Code));
    }

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        if (VitalParameterDefinition::get()->exists()) {
            return;
        }
        $sort = 10;
        foreach ((array) static::config()->get('default_definitions') as $definition) {
            $record = VitalParameterDefinition::create($definition);
            $record->SortOrder = $sort;
            $record->IsActive = true;
            $record->write();
            $sort += 10;
        }
    }

    public function hasPlausibilityRange(): bool
    {
        return !((float) $this->MinValue === 0.0 && (float) $this->MaxValue === 0.0);
    }

    public function hasNormalRange(): bool
    {
        return !((float) $this->NormalMin === 0.0 && (float) $this->NormalMax === 0.0);
    }

    public function isPlausible(float $value): bool
    {
        return !$this->hasPlausibilityRange()
            || ($value >= (float) $this->MinValue && $value <= (float) $this->MaxValue);
    }

    public function isAbnormal(float $value): bool
    {
        return $this->hasNormalRange()
            && ($value < (float) $this->NormalMin || $value > (float) $this->NormalMax);
    }

    public function getLabelWithUnit(): string
    {
        return $this->Unit ? sprintf('%s (%s)', $this->Title, $this->Unit) : (string) $this->Title;
    }

    public function getRangeLabel(): string
    {
        if (!$this->hasNormalRange()) {
            return '—';
        }

        return $this->formatValue((float) $this->NormalMin) . '–' . $this->formatValue((float) $this->NormalMax)
            . ($this->Unit ? ' ' . $this->Unit : '');
    }

    public function formatValue(float $value): string
    {
        if ($this->ValueType === self::TYPE_INTEGER) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    public function canView($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canEdit($member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }

    /**
     * Definitions with recorded measurements can only be deactivated, never deleted.
     */
    public function canDelete($member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member)
            && (!$this->isInDB() || $this->Readings()->count() === 0);
    }
}
