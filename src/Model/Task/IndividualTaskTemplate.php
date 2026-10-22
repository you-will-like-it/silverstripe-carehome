<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use DateTimeInterface;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Resident-specific task (medication, specialist visit, ...).
 *
 * Changing therapy "from tomorrow": set EndDate = today on the current template and add a new one
 * with StartDate = tomorrow. Editing the existing template instead updates every still-open task
 * from now on (including today's remaining doses).
 *
 * @property string|null $StartDate
 * @property string|null $EndDate
 * @property bool $IsOneOff
 * @property int $PatientID
 * @method Patient Patient()
 */
class IndividualTaskTemplate extends TaskTemplate
{
    private static string $table_name = 'CareHome_IndividualTaskTemplate';

    private static array $db = [
        'StartDate' => 'Date',
        'EndDate' => 'Date',
        'IsOneOff' => 'Boolean',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
    ];

    private static array $summary_fields = [
        'Title',
        'CategoryLabel',
        'TherapyLabel',
        'ScheduledTime.Short',
        'ScheduleLabel',
        'IsActive.Nice',
    ];

    private static array $casting = [
        'ScheduleLabel' => 'Varchar',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Individualni zadatak');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Individualni zadaci');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'StartDate' => _t(__CLASS__ . '.STARTDATE', 'Od datuma'),
            'EndDate' => _t(__CLASS__ . '.ENDDATE', 'Do datuma'),
            'IsOneOff' => _t(__CLASS__ . '.ISONEOFF', 'Jednokratno (samo na datum „Od")'),
            'ScheduleLabel' => _t(__CLASS__ . '.SCHEDULE', 'Raspored'),
            'TherapyLabel' => _t(__CLASS__ . '.THERAPY', 'Terapija'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['StartDate', 'EndDate', 'IsOneOff', 'PatientID']);

        $fields->addFieldsToTab('Root.Main', [
            DateField::create('StartDate', $this->fieldLabel('StartDate')),
            DateField::create('EndDate', $this->fieldLabel('EndDate'))
                ->setDescription(_t(__CLASS__ . '.ENDDATE_DESC', 'Prazno = bez kraja.')),
            CheckboxField::create('IsOneOff', $this->fieldLabel('IsOneOff')),
        ], 'DaysOfWeek');

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!$this->StartDate) {
            $result->addFieldError('StartDate', _t(__CLASS__ . '.REQ_START', 'Datum početka je obavezan.'));
        }
        if ($this->StartDate && $this->EndDate && $this->EndDate < $this->StartDate) {
            $result->addFieldError('EndDate', _t(__CLASS__ . '.END_BEFORE_START', 'Datum završetka je prije početka.'));
        }
        if (!$this->PatientID) {
            $result->addError(_t(__CLASS__ . '.REQ_PATIENT', 'Zadatak mora pripadati štićeniku.'));
        }

        return $result;
    }

    protected function requiresDays(): bool
    {
        return !$this->IsOneOff;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->IsOneOff) {
            $this->EndDate = $this->StartDate;
        }
    }

    public function occursOn(DateTimeInterface $date): bool
    {
        if (!$this->IsActive || !$this->StartDate) {
            return false;
        }
        $ymd = $date->format('Y-m-d');
        if ($ymd < $this->StartDate) {
            return false;
        }
        if ($this->IsOneOff) {
            return $ymd === $this->StartDate;
        }
        if ($this->EndDate && $ymd > $this->EndDate) {
            return false;
        }

        return DaysOfWeek::matches($this->getDays(), $date);
    }

    public function affectedPatientIds(): array
    {
        return $this->PatientID ? [(int) $this->PatientID] : [];
    }

    public function getTherapyLabel(): string
    {
        if ($this->Category !== self::CATEGORY_THERAPY) {
            return '';
        }
        $route = self::routeOptions()[$this->Route] ?? '';

        return trim(implode(' · ', array_filter([(string) $this->Medication, (string) $this->Dose, $route])));
    }

    public function getScheduleLabel(): string
    {
        if ($this->IsOneOff) {
            return _t(__CLASS__ . '.ONEOFF_ON', 'Jednokratno {date}', ['date' => $this->dbObject('StartDate')->Nice()]);
        }
        $range = $this->dbObject('StartDate')->Nice()
            . ' – '
            . ($this->EndDate ? $this->dbObject('EndDate')->Nice() : '∞');

        return $this->getDaysLabel() . ' (' . $range . ')';
    }
}
