<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Admin;

use SilverStripe\Admin\ModelAdmin;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Residents. Tasks, notes, meals, bed history and the medical report live on the resident record.
 */
class PatientAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-residents';

    private static $menu_title = 'Štićenici';

    private static $menu_icon_class = 'font-icon-torsos-all';

    private static $menu_priority = 11;

    private static $managed_models = [
        'residents' => [
            'dataClass' => Patient::class,
            'title' => 'Štićenici',
        ],
    ];

    /** Medical data: no CSV import of residents. */
    public $showImportForm = false;
}
