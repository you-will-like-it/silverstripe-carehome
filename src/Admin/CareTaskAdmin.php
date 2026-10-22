<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Admin;

use SilverStripe\Admin\ModelAdmin;
use YouWillLikeIT\CareHome\Model\Task\GlobalTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\VitalParameterDefinition;

/**
 * Global task templates and vital parameter definitions
 * (definitions editable by superadmins only — see VitalParameterDefinition permissions).
 */
class CareTaskAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-tasks';

    private static $menu_title = 'Zadaci';

    private static $menu_icon_class = 'font-icon-checklist';

    private static $menu_priority = 9;

    private static $managed_models = [
        'global-tasks' => [
            'dataClass' => GlobalTaskTemplate::class,
            'title' => 'Globalni zadaci',
        ],
        'vital-parameters' => [
            'dataClass' => VitalParameterDefinition::class,
            'title' => 'Vitalni parametri',
        ],
    ];

    public $showImportForm = false;
}
