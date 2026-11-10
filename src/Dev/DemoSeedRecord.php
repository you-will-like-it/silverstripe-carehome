<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Dev;

use SilverStripe\ORM\DataObject;

/**
 * Registry of records created by the demo seeder, so a re-run (or --purge) removes exactly
 * the demo data and never touches real records.
 *
 * @property string $ObjectClass
 * @property int $ObjectID
 */
class DemoSeedRecord extends DataObject
{
    private static string $table_name = 'CareHome_DemoSeedRecord';

    private static array $db = [
        'ObjectClass' => 'Varchar(255)',
        'ObjectID' => 'Int',
    ];

    private static array $indexes = [
        'Object' => ['type' => 'unique', 'columns' => ['ObjectClass', 'ObjectID']],
    ];

    public static function register(DataObject $record): void
    {
        $entry = self::create();
        $entry->ObjectClass = $record::class;
        $entry->ObjectID = (int) $record->ID;
        $entry->write();
    }

    /**
     * @return list<int>
     */
    public static function idsOf(string $class): array
    {
        return array_map('intval', self::get()->filter('ObjectClass', $class)->column('ObjectID'));
    }

    public function canView($member = null): bool
    {
        return false;
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
