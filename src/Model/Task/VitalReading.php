<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * One measured value of one vital parameter. Title/Unit are snapshotted so historic
 * reports stay correct after a definition is renamed.
 *
 * @property float $Value
 * @property string $MeasuredAt
 * @property bool $IsAbnormal
 * @property string $DefinitionTitle
 * @property string $Unit
 * @property int $TaskID
 * @property int $PatientID
 * @property int $DefinitionID
 * @method DailyTask Task()
 * @method Patient Patient()
 * @method VitalParameterDefinition Definition()
 */
class VitalReading extends DataObject
{
    private static string $table_name = 'CareHome_VitalReading';

    private static array $db = [
        'Value' => 'Decimal(10,2)',
        'MeasuredAt' => 'Datetime',
        'IsAbnormal' => 'Boolean',
        'DefinitionTitle' => 'Varchar(100)',
        'Unit' => 'Varchar(20)',
    ];

    private static array $has_one = [
        'Task' => DailyTask::class,
        'Patient' => Patient::class,
        'Definition' => VitalParameterDefinition::class,
    ];

    private static array $indexes = [
        'PatientMeasured' => ['type' => 'index', 'columns' => ['PatientID', 'MeasuredAt']],
        'TaskDefinition' => ['type' => 'unique', 'columns' => ['TaskID', 'DefinitionID']],
    ];

    private static string $default_sort = '"MeasuredAt" DESC';

    private static array $summary_fields = [
        'DefinitionTitle',
        'FormattedValue',
        'IsAbnormal.Nice',
        'MeasuredAt.Nice',
    ];

    private static array $casting = [
        'FormattedValue' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Izmjerena vrijednost');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Izmjerene vrijednosti');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'DefinitionTitle' => _t(__CLASS__ . '.PARAM', 'Parametar'),
            'Value' => _t(__CLASS__ . '.VALUE', 'Vrijednost'),
            'FormattedValue' => _t(__CLASS__ . '.VALUE', 'Vrijednost'),
            'IsAbnormal' => _t(__CLASS__ . '.ABNORMAL', 'Izvan opsega'),
            'IsAbnormal.Nice' => _t(__CLASS__ . '.ABNORMAL', 'Izvan opsega'),
            'MeasuredAt' => _t(__CLASS__ . '.MEASURED', 'Izmjereno'),
            'MeasuredAt.Nice' => _t(__CLASS__ . '.MEASURED', 'Izmjereno'),
        ]);
    }

    public function getFormattedValue(): string
    {
        $definition = $this->Definition();
        $value = $definition->exists()
            ? $definition->formatValue((float) $this->Value)
            : rtrim(rtrim(number_format((float) $this->Value, 2, ',', ''), '0'), ',');

        return $value . ($this->Unit ? ' ' . $this->Unit : '');
    }

    public function canView($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    /** Corrections by CareHome admins are allowed and audit-logged. */
    public function canEdit($member = null): bool
    {
        return CareHomePermissions::check(CareHomePermissions::ADMIN, $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }
}
