<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Control\Portal;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;
use YouWillLikeIT\CareHome\Security\PortalAuthenticator;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\View\ViteAssets;

/**
 * Shared plumbing for PIN-based front-ends (/portal, /kuhinja):
 * session auth, CSRF, flash messages, security headers, no caching of health data.
 */
abstract class AbstractPortalController extends Controller
{
    private const string FLASH_KEY = 'CareHomePortalFlash';

    private ?Member $worker = null;

    private bool $workerResolved = false;

    abstract protected function permissionCode(): string;

    abstract protected function auditChannel(): string;

    protected function init(): void
    {
        parent::init();

        HTTPCacheControlMiddleware::singleton()->disableCache(true);

        $response = $this->getResponse();
        $response->addHeader('X-Frame-Options', 'DENY');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('Referrer-Policy', 'same-origin');
        $response->addHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        $context = AuditContext::singleton()->setChannel($this->auditChannel());
        if ($worker = $this->currentWorker()) {
            $context->setActor($worker);
        }
    }

    public function currentWorker(): ?Member
    {
        if (!$this->workerResolved) {
            $this->worker = PortalAuthenticator::singleton()->currentWorker($this->getRequest(), $this->permissionCode());
            $this->workerResolved = true;
        }

        return $this->worker;
    }

    protected function requireWorker(): Member|HTTPResponse
    {
        $worker = $this->currentWorker();
        if ($worker) {
            return $worker;
        }
        if ($this->getRequest()->isAjax()) {
            return $this->getResponse()->setStatusCode(401)->setBody('');
        }

        return $this->redirect($this->Link());
    }

    /**
     * @throws HTTPResponse_Exception on invalid token
     */
    protected function assertPostWithToken(HTTPRequest $request, string $backUrl): void
    {
        if (!$request->isPOST()) {
            // e.g. browser refresh on a form target: just go back
            throw new HTTPResponse_Exception($this->redirect($backUrl));
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            $this->flash('error', _t(__CLASS__ . '.TOKEN', 'Sesija je istekla, pokušajte ponovo.'));
            throw new HTTPResponse_Exception($this->redirect($backUrl));
        }
    }

    protected function flash(string $type, string $message): void
    {
        $this->getRequest()->getSession()->set(self::FLASH_KEY, ['type' => $type, 'message' => $message]);
    }

    public function FlashMessage(): ?ArrayData
    {
        $session = $this->getRequest()->getSession();
        $flash = $session->get(self::FLASH_KEY);
        if (!is_array($flash)) {
            return null;
        }
        $session->clear(self::FLASH_KEY);

        return ArrayData::create($flash);
    }

    public function ViteTags(): DBHTMLText
    {
        return ViteAssets::singleton()->tags();
    }

    public function SecurityTokenName(): string
    {
        return SecurityToken::inst()->getName();
    }

    public function SecurityTokenValue(): string
    {
        return SecurityToken::inst()->getValue();
    }

    public function ContentLocale(): string
    {
        return (string) i18n::convert_rfc1766(i18n::get_locale());
    }

    public function Worker(): ?Member
    {
        return $this->currentWorker();
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function renderPage(string $template, array $data = []): DBHTMLText
    {
        return $this->customise($data)->renderWith($template);
    }
}
