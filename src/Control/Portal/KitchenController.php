<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Control\Portal;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Security\PortalAuthenticator;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\KitchenSummaryService;

/**
 * /kuhinja – meal counts per day and option, printable.
 * Access: PIN login with the Kitchen role, or a CMS user holding CAREHOME_KITCHEN (admins print from the CMS).
 */
class KitchenController extends AbstractPortalController
{
    private static string $url_segment = 'kuhinja';

    private static array $allowed_actions = [
        'index',
        'login',
        'logout',
    ];

    private static array $url_handlers = [
        'login' => 'login',
        'logout' => 'logout',
    ];

    protected function permissionCode(): string
    {
        return CareHomePermissions::KITCHEN;
    }

    protected function auditChannel(): string
    {
        return AuditContext::CHANNEL_KITCHEN;
    }

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), $action);
    }

    public static function printLink(string $range): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), '?range=' . rawurlencode($range) . '&print=1');
    }

    /**
     * CMS users with the kitchen permission do not need a PIN.
     */
    public function currentWorker(): ?Member
    {
        $cmsUser = Security::getCurrentUser();
        if ($cmsUser && CareHomePermissions::check(CareHomePermissions::KITCHEN, $cmsUser)) {
            return $cmsUser;
        }

        return parent::currentWorker();
    }

    public function IsCmsSession(): bool
    {
        $cmsUser = Security::getCurrentUser();

        return $cmsUser !== null && CareHomePermissions::check(CareHomePermissions::KITCHEN, $cmsUser);
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        if (!$this->currentWorker()) {
            return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Login', [
                'Title' => _t(__CLASS__ . '.LOGIN_TITLE', 'Kuhinja – prijava'),
                'LoginAction' => $this->Link('login'),
            ]);
        }

        $range = (string) $request->getVar('range');
        if (!in_array($range, ['today', 'tomorrow', 'week'], true)) {
            $range = 'tomorrow';
        }
        $service = KitchenSummaryService::singleton();
        [$from, $to] = $service->range($range);

        $ranges = ArrayList::create();
        foreach ([
            'today' => _t(__CLASS__ . '.TODAY', 'Danas'),
            'tomorrow' => _t(__CLASS__ . '.TOMORROW', 'Sutra'),
            'week' => _t(__CLASS__ . '.WEEK', 'Sedmica'),
        ] as $key => $label) {
            $ranges->push(ArrayData::create([
                'Key' => $key,
                'Label' => $label,
                'Link' => $this->Link('?range=' . $key),
                'Active' => $key === $range,
            ]));
        }

        $template = $request->getVar('print')
            ? 'YouWillLikeIT\\CareHome\\Portal\\KitchenPrint'
            : 'YouWillLikeIT\\CareHome\\Portal\\Kitchen';

        return $this->renderPage($template, [
            'Title' => _t(__CLASS__ . '.TITLE', 'Kuhinja'),
            'Ranges' => $ranges,
            'PrintLink' => self::printLink($range),
            'Days' => $service->summary($from, $to),
            'DietNotes' => $service->dietNotes(),
            'GeneratedAt' => DBField::create_field('Datetime', Clock::singleton()->toDbDatetime(Clock::singleton()->now())),
        ]);
    }

    public function login(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());
        $result = PortalAuthenticator::singleton()->attempt(
            $request,
            (string) $request->postVar('pin'),
            [$this->permissionCode(), CareHomePermissions::PORTAL]
        );
        if ($result['error']) {
            $this->flash('error', $result['error']);
        }

        // care worker PIN entered here: continue in the worker portal
        return $this->redirect($result['area'] === CareHomePermissions::PORTAL
            ? PortalController::singleton()->Link()
            : $this->Link());
    }

    public function logout(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());
        PortalAuthenticator::singleton()->logout($request, parent::currentWorker(), $this->permissionCode());

        return $this->redirect($this->Link());
    }
}
