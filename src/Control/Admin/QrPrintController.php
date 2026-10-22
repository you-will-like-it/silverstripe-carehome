<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Control\Admin;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Facility\FacilityRoom;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\QrCodeService;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Printable QR labels for beds (single bed, whole room, whole zone incl. sub-zones).
 */
class QrPrintController extends Controller
{
    private static string $url_segment = 'carehome-qr';

    private static array $allowed_actions = ['bed', 'room', 'zone'];

    private static array $url_handlers = [
        'bed/$ID!' => 'bed',
        'room/$ID!' => 'room',
        'zone/$ID!' => 'zone',
    ];

    public static function linkFor(string $type, int $id): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), $type, $id);
    }

    protected function init(): void
    {
        parent::init();
        HTTPCacheControlMiddleware::singleton()->disableCache(true);

        $member = Security::getCurrentUser();
        if (!$member || !CareHomePermissions::check(CareHomePermissions::ADMIN, $member)) {
            Security::permissionFailure($this);
        }
    }

    public function bed(HTTPRequest $request): DBHTMLText
    {
        return $this->renderLabels(FacilityBed::get()->filter('ID', (int) $request->param('ID')));
    }

    public function room(HTTPRequest $request): DBHTMLText
    {
        $room = FacilityRoom::get()->byID((int) $request->param('ID'));
        if (!$room) {
            $this->httpError(404);
        }

        return $this->renderLabels($room->Beds());
    }

    public function zone(HTTPRequest $request): DBHTMLText
    {
        $zone = FacilityZone::get()->byID((int) $request->param('ID'));
        if (!$zone) {
            $this->httpError(404);
        }
        $zoneIds = ZoneScope::singleton()->descendantIds([(int) $zone->ID]);

        return $this->renderLabels(FacilityBed::get()->filter('Room.ZoneID', $zoneIds));
    }

    /**
     * @param DataList<FacilityBed>|\SilverStripe\ORM\HasManyList<FacilityBed> $beds
     */
    private function renderLabels($beds): DBHTMLText
    {
        $qr = QrCodeService::singleton();
        $labels = [];
        foreach ($beds as $bed) {
            $room = $bed->Room();
            $labels[] = [
                'Svg' => DBField::create_field('HTMLFragment', $qr->svg($bed->getPortalURL())),
                'BedLabel' => $bed->BedLabel,
                'RoomNumber' => $room->RoomNumber,
                'ZoneTitle' => $room->Zone()->getBreadcrumb(),
                'SortKey' => $room->Zone()->getBreadcrumb() . '|' . $room->RoomNumber . '|' . $bed->BedLabel,
            ];
        }
        if ($labels === []) {
            $this->httpError(404);
        }
        usort($labels, static fn (array $a, array $b): int => strnatcasecmp($a['SortKey'], $b['SortKey']));

        return $this->customise([
            'Title' => _t(__CLASS__ . '.TITLE', 'QR oznake kreveta'),
            'Labels' => ArrayList::create(array_map(static fn (array $l): ArrayData => ArrayData::create($l), $labels)),
        ])->renderWith('YouWillLikeIT\\CareHome\\Admin\\QrPrint');
    }
}
