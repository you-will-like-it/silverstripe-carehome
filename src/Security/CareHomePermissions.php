<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Security;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Central permission codes of the CareHome module.
 *
 * Group <-> permission mapping is created in MemberCareExtension::requireDefaultRecords().
 */
final class CareHomePermissions implements PermissionProvider
{
    /** Manage residents, facility, menus, templates in the CMS */
    public const string ADMIN = 'CAREHOME_ADMIN';

    /** Generate medical PDF reports */
    public const string REPORTS = 'CAREHOME_REPORTS';

    /** Use the worker portal (/portal) */
    public const string PORTAL = 'CAREHOME_PORTAL';

    /** Use the kitchen view (/kuhinja) */
    public const string KITCHEN = 'CAREHOME_KITCHEN';

    /** Read the audit log */
    public const string AUDIT = 'CAREHOME_AUDIT';

    /** Group codes created by the module */
    public const string GROUP_ADMINS = 'carehome-administrators';
    public const string GROUP_WORKERS = 'carehome-careworkers';
    public const string GROUP_KITCHEN = 'carehome-kitchen';

    public function providePermissions(): array
    {
        $category = _t(__CLASS__ . '.CATEGORY', 'Dom za njegu');

        return [
            self::ADMIN => [
                'name' => _t(__CLASS__ . '.ADMIN', 'Upravljanje domom (CMS)'),
                'category' => $category,
            ],
            self::REPORTS => [
                'name' => _t(__CLASS__ . '.REPORTS', 'Ljekarski PDF izvještaji'),
                'category' => $category,
            ],
            self::PORTAL => [
                'name' => _t(__CLASS__ . '.PORTAL', 'Radnički portal'),
                'category' => $category,
            ],
            self::KITCHEN => [
                'name' => _t(__CLASS__ . '.KITCHEN', 'Kuhinjski pregled'),
                'category' => $category,
            ],
            self::AUDIT => [
                'name' => _t(__CLASS__ . '.AUDIT', 'Pregled audit loga'),
                'category' => $category,
            ],
        ];
    }

    public static function check(string $code, ?Member $member = null): bool
    {
        return (bool) Permission::checkMember($member, $code);
    }

    /**
     * Superadmin = holder of the core ADMIN permission (not the CareHome admin group).
     */
    public static function isSuperAdmin(?Member $member = null): bool
    {
        return (bool) Permission::checkMember($member, 'ADMIN');
    }
}
