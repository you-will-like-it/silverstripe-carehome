<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Security;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Cache\RateLimiter;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Service\AuditLogger;

/**
 * PIN-only login for /portal and /kuhinja.
 *
 * - Separate session namespace: a PIN login never creates a CMS session.
 * - Rate limited per client IP (LAN devices have distinct IPs).
 * - Session id regenerated on login (fixation), idle timeout + absolute lifetime.
 */
class PortalAuthenticator
{
    use Injectable;
    use Configurable;

    private const string SESSION_KEY = 'CareHomePortal';

    /** Failed attempts allowed per IP within lockout_seconds. */
    private static int $max_attempts = 8;

    private static int $lockout_seconds = 900;

    /** Logout after this many seconds without a request. Personal device per shift → generous. */
    private static int $idle_timeout = 7200;

    /** Hard session lifetime (longest shift + margin). */
    private static int $absolute_lifetime = 50400;

    public function __construct(
        private readonly PinHasher $hasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{member:?Member, error:?string}
     */
    public function attempt(HTTPRequest $request, string $pin, string $permissionCode): array
    {
        $limiter = $this->limiter($request);
        if (!$limiter->canAccess()) {
            $minutes = (int) ceil($limiter->getTimeToReset() / 60);

            return ['member' => null, 'error' => _t(
                __CLASS__ . '.LOCKED',
                'Previše pokušaja. Pokušajte ponovo za {minutes} min.',
                ['minutes' => max(1, $minutes)]
            )];
        }

        $pin = trim($pin);
        $member = null;
        if ($this->hasher->isValidFormat($pin)) {
            /** @var Member|null $member */
            $member = Member::get()->filter('WorkerPinLookup', $this->hasher->lookup($pin))->first();
        }

        if (!$member
            || !$member->isPortalWorker()
            || $member->isLockedOut()
            || !CareHomePermissions::check($permissionCode, $member)
        ) {
            $limiter->hit();
            $this->auditLogger->logEvent(
                AuditLogEntry::ACTION_LOGIN_FAILED,
                _t(__CLASS__ . '.AUDIT_FAILED', 'Neuspjela PIN prijava'),
                null,
                ['permission' => $permissionCode, 'knownPin' => $member !== null]
            );

            return ['member' => null, 'error' => _t(__CLASS__ . '.INVALID', 'Pogrešan PIN.')];
        }

        $limiter->clearAttempts();
        $session = $request->getSession();
        $session->regenerateSessionId();
        $now = time();
        $session->set(self::SESSION_KEY, [
            'memberID' => (int) $member->ID,
            'loginAt' => $now,
            'lastSeen' => $now,
        ]);

        $this->auditLogger->logEvent(
            AuditLogEntry::ACTION_LOGIN,
            _t(__CLASS__ . '.AUDIT_LOGIN', 'PIN prijava'),
            $member,
            ['permission' => $permissionCode]
        );

        return ['member' => $member, 'error' => null];
    }

    /**
     * Current worker or null (expired / revoked / missing permission). Touches lastSeen.
     */
    public function currentWorker(HTTPRequest $request, string $permissionCode): ?Member
    {
        $session = $request->getSession();
        $data = $session->get(self::SESSION_KEY);
        if (!is_array($data) || empty($data['memberID'])) {
            return null;
        }

        $now = time();
        $idle = (int) static::config()->get('idle_timeout');
        $lifetime = (int) static::config()->get('absolute_lifetime');
        if ($now - (int) ($data['lastSeen'] ?? 0) > $idle || $now - (int) ($data['loginAt'] ?? 0) > $lifetime) {
            $session->clear(self::SESSION_KEY);

            return null;
        }

        /** @var Member|null $member */
        $member = Member::get()->byID((int) $data['memberID']);
        // PIN removed, portal disabled or role changed after login → session is void
        if (!$member || !$member->isPortalWorker() || !CareHomePermissions::check($permissionCode, $member)) {
            $session->clear(self::SESSION_KEY);

            return null;
        }

        $data['lastSeen'] = $now;
        $session->set(self::SESSION_KEY, $data);

        return $member;
    }

    public function logout(HTTPRequest $request, ?Member $member): void
    {
        if ($member) {
            $this->auditLogger->logEvent(
                AuditLogEntry::ACTION_LOGOUT,
                _t(__CLASS__ . '.AUDIT_LOGOUT', 'PIN odjava'),
                $member
            );
        }
        $session = $request->getSession();
        $session->clear(self::SESSION_KEY);
        $session->regenerateSessionId();
    }

    private function limiter(HTTPRequest $request): RateLimiter
    {
        return RateLimiter::create(
            'carehome-pin-' . sha1((string) $request->getIP()),
            (int) static::config()->get('max_attempts'),
            // RateLimiter decay is expressed in minutes
            max(1, (int) ceil((int) static::config()->get('lockout_seconds') / 60))
        );
    }
}
