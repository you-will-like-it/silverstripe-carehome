<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\ORM\DataList;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Note\ShiftHandoverLog;

/**
 * Facility hierarchy (top-level zones; sub-zones, rooms and beds are nested GridFields)
 * and shift handover logs.
 */
class FacilityAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-facility';

    private static $menu_title = 'Smještaj';

    private static $menu_icon_class = 'font-icon-home';

    private static $menu_priority = 10;

    private static $managed_models = [
        'zones' => [
            'dataClass' => FacilityZone::class,
            'title' => 'Zgrade i zone',
        ],
        'handover' => [
            'dataClass' => ShiftHandoverLog::class,
            'title' => 'Primopredaje smjena',
        ],
    ];

    public function getList(): DataList
    {
        $list = parent::getList();

        // children are edited inside their parent only
        if ($this->getModelClass() === FacilityZone::class) {
            $list = $list->filter('ParentID', 0);
        }

        return $list;
    }
}
