<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Admin;

use SilverStripe\Admin\ModelAdmin;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;

/**
 * Read-only audit trail (AuditLogEntry denies create/edit/delete for everyone).
 */
class AuditLogAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-audit';

    private static $menu_title = 'Audit log';

    private static $menu_icon_class = 'font-icon-eye';

    private static $menu_priority = 1;

    private static $managed_models = [
        'audit' => [
            'dataClass' => AuditLogEntry::class,
            'title' => 'Audit log',
        ],
    ];

    private static $page_length = 100;

    public $showImportForm = false;
}
