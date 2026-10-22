<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use DateTimeInterface;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RelationEditor;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyThroughList;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Recurring task shared by many residents (e.g. "Jutarnje mjerenje pritiska").
 * Assignment goes through PatientGlobalTask so link/unlink triggers a resync.
 *
 * @method ManyManyThroughList<Patient> Patients()
 * @method HasManyList<PatientGlobalTask> PatientLinks()
 */
class GlobalTaskTemplate extends TaskTemplate
{
    private static string $table_name = 'CareHome_GlobalTaskTemplate';

    private static array $many_many = [
        'Patients' => [
            'through' => PatientGlobalTask::class,
            'from' => 'Template',
            'to' => 'Patient',
        ],
    ];

    private static array $has_many = [
        'PatientLinks' => PatientGlobalTask::class . '.Template',
    ];

    private static array $cascade_deletes = [
        'PatientLinks',
    ];

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Globalni zadatak');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Globalni zadaci');
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Patients' => _t(__CLASS__ . '.PATIENTS', 'Dodijeljeni štićenici'),
        ]);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Patients', 'PatientLinks']);

        if ($this->isInDB()) {
            $config = GridFieldConfig_RelationEditor::create(50);
            $config->removeComponentsByType(GridFieldAddNewButton::class);

            $fields->addFieldToTab('Root.' . _t(__CLASS__ . '.TAB_PATIENTS', 'Štićenici'), GridField::create(
                'Patients',
                $this->fieldLabel('Patients'),
                $this->Patients(),
                $config
            ));
        }

        return $fields;
    }

    public function occursOn(DateTimeInterface $date): bool
    {
        return (bool) $this->IsActive && DaysOfWeek::matches($this->getDays(), $date);
    }

    public function affectedPatientIds(): array
    {
        if (!$this->isInDB()) {
            return [];
        }

        return array_map('intval', $this->PatientLinks()->column('PatientID'));
    }
}
