<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\TaskGenerator;

/**
 * Join object Patient <-> GlobalTaskTemplate ("many_many through").
 * Being a real DataObject, linking/unlinking fires write/delete hooks → resync + audit log.
 *
 * @property int $PatientID
 * @property int $TemplateID
 * @method Patient Patient()
 * @method GlobalTaskTemplate Template()
 */
class PatientGlobalTask extends DataObject
{
    private static string $table_name = 'CareHome_PatientGlobalTask';

    private static array $has_one = [
        'Patient' => Patient::class,
        'Template' => GlobalTaskTemplate::class,
    ];

    private static array $indexes = [
        'PatientTemplate' => ['type' => 'unique', 'columns' => ['PatientID', 'TemplateID']],
    ];

    private int $patientBeforeDelete = 0;

    public function getTitle(): string
    {
        return $this->Patient()->getFullName() . ' ↔ ' . $this->Template()->Title;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        TaskGenerator::singleton()->syncPatients([(int) $this->PatientID]);
    }

    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        $this->patientBeforeDelete = (int) $this->PatientID;
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        TaskGenerator::singleton()->syncPatients([$this->patientBeforeDelete]);
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
