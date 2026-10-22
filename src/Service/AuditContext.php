<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Request-scoped "who and where" for audit entries.
 * Portal/kitchen controllers set the PIN-authenticated worker as actor, because PIN workers
 * are deliberately NOT logged into the CMS session (Security::getCurrentUser() stays empty).
 */
class AuditContext
{
    use Injectable;

    public const string CHANNEL_CMS = 'cms';
    public const string CHANNEL_PORTAL = 'portal';
    public const string CHANNEL_KITCHEN = 'kitchen';
    public const string CHANNEL_CLI = 'cli';
    public const string CHANNEL_SYSTEM = 'system';

    private ?Member $actor = null;

    private ?string $channel = null;

    /** Nesting counter for suppress() */
    private int $suppressed = 0;

    public function setActor(?Member $member): static
    {
        $this->actor = $member;

        return $this;
    }

    public function getActor(): ?Member
    {
        return $this->actor ?? Security::getCurrentUser();
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getChannel(): string
    {
        if ($this->channel !== null) {
            return $this->channel;
        }
        if (Director::is_cli()) {
            return self::CHANNEL_CLI;
        }

        return Security::getCurrentUser() ? self::CHANNEL_CMS : self::CHANNEL_SYSTEM;
    }

    public function getIP(): string
    {
        $controller = Director::is_cli() ? null : Controller::curr();
        if (!$controller) {
            return '';
        }

        return (string) $controller->getRequest()->getIP();
    }

    /**
     * Run a callback without audit logging (only for the audit writer itself / bulk maintenance).
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function suppress(callable $callback): mixed
    {
        $this->suppressed++;
        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed > 0;
    }
}
