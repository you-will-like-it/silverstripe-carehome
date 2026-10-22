<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use WeakMap;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Service\AuditLogger;

/**
 * Field-level audit trail for any DataObject it is applied to.
 *
 * Silverstripe 6 has no built-in data change log: silverstripe/auditor only logs logins,
 * publishing and security changes to syslog, and Versioned history only covers versioned
 * records. This extension records create/update/delete with before/after values.
 *
 * Extension instances are shared singletons (Injector::get), so per-record state lives in
 * a WeakMap keyed by the owner object.
 *
 * @extends Extension<DataObject>
 */
class AuditableExtension extends Extension
{
    /** @var WeakMap<DataObject,bool>|null */
    private static ?WeakMap $newRecords = null;

    /** @var WeakMap<DataObject,array<string,mixed>>|null */
    private static ?WeakMap $deletedSnapshots = null;

    private static function newRecords(): WeakMap
    {
        return self::$newRecords ??= new WeakMap();
    }

    private static function deletedSnapshots(): WeakMap
    {
        return self::$deletedSnapshots ??= new WeakMap();
    }

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        self::newRecords()[$owner] = !$owner->isInDB();
    }

    protected function onAfterWrite(): void
    {
        $owner = $this->getOwner();
        if ($owner instanceof AuditLogEntry) {
            return;
        }

        $isNew = self::newRecords()[$owner] ?? false;
        unset(self::newRecords()[$owner]);

        $changes = [];
        foreach ($owner->getChangedFields(true, DataObject::CHANGE_VALUE) as $field => $change) {
            $changes[$field] = [
                'before' => $isNew ? null : ($change['before'] ?? null),
                'after' => $change['after'] ?? null,
            ];
        }

        AuditLogger::singleton()->logObject(
            $isNew ? AuditLogEntry::ACTION_CREATE : AuditLogEntry::ACTION_UPDATE,
            $owner,
            $changes
        );
    }

    protected function onBeforeDelete(): void
    {
        $owner = $this->getOwner();
        self::deletedSnapshots()[$owner] = $owner->toMap();
    }

    protected function onAfterDelete(): void
    {
        $owner = $this->getOwner();
        if ($owner instanceof AuditLogEntry) {
            return;
        }

        $snapshot = self::deletedSnapshots()[$owner] ?? [];
        unset(self::deletedSnapshots()[$owner]);

        $fieldSpecs = DataObject::getSchema()->fieldSpecs($owner::class);
        $changes = [];
        foreach ($snapshot as $field => $value) {
            if (isset($fieldSpecs[$field]) && $field !== 'ID') {
                $changes[$field] = ['before' => $value, 'after' => null];
            }
        }

        AuditLogger::singleton()->logObject(AuditLogEntry::ACTION_DELETE, $owner, $changes);
    }
}
