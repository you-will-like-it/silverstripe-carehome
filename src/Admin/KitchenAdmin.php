<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use YouWillLikeIT\CareHome\Control\Portal\KitchenController;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Model\Patient\DietaryTag;

/**
 * Weekly menu and optional diet tags, plus links to the printable kitchen summary.
 */
class KitchenAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-kitchen';

    private static $menu_title = 'Kuhinja';

    private static $menu_icon_class = 'font-icon-p-shop';

    private static $menu_priority = 8;

    private static $managed_models = [
        'menu' => [
            'dataClass' => WeeklyMenuItem::class,
            'title' => 'Jelovnik',
        ],
        'diet-tags' => [
            'dataClass' => DietaryTag::class,
            'title' => 'Dijetetske oznake',
        ],
    ];

    public $showImportForm = false;

    public function getEditForm($id = null, $fields = null): Form
    {
        $form = parent::getEditForm($id, $fields);

        if ($this->getModelClass() === WeeklyMenuItem::class) {
            $links = [];
            foreach ([
                'today' => _t(__CLASS__ . '.TODAY', 'Danas'),
                'tomorrow' => _t(__CLASS__ . '.TOMORROW', 'Sutra'),
                'week' => _t(__CLASS__ . '.WEEK', 'Sedmica'),
            ] as $range => $label) {
                $links[] = sprintf(
                    '<a class="btn btn-outline-secondary font-icon-print" target="_blank" rel="noopener" href="%s">%s</a>',
                    htmlspecialchars(KitchenController::printLink($range)),
                    htmlspecialchars($label)
                );
            }
            $form->Fields()->unshift(LiteralField::create('KitchenPrintLinks', sprintf(
                '<div class="form-group" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><strong>%s</strong>%s</div>',
                _t(__CLASS__ . '.PRINT', 'Zbirni pregled za štampu:'),
                implode('', $links)
            )));
        }

        return $form;
    }
}
