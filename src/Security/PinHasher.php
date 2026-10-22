<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Security;

use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * PIN-only login needs a deterministic lookup value, so PINs are stored as
 * HMAC-SHA256(pepper, PIN). The pepper lives in .env (CAREHOME_PIN_PEPPER), not in the DB:
 * a DB dump alone does not reveal PINs. A 4–6 digit space is small by nature — the real
 * protection is the pepper plus login rate limiting (see PortalAuthenticator).
 */
class PinHasher
{
    use Injectable;
    use Configurable;

    public const string ENV_PEPPER = 'CAREHOME_PIN_PEPPER';

    private static int $min_length = 4;

    private static int $max_length = 6;

    /** Reject 0000, 1111, 1234, 4321, 123456, ... */
    private static bool $reject_trivial = true;

    public function isConfigured(): bool
    {
        return strlen((string) Environment::getEnv(self::ENV_PEPPER)) >= 32;
    }

    public function lookup(string $pin): string
    {
        $pepper = (string) Environment::getEnv(self::ENV_PEPPER);
        if (strlen($pepper) < 32) {
            throw new RuntimeException(self::ENV_PEPPER . ' must be set in .env (min. 32 characters).');
        }

        return hash_hmac('sha256', $pin, $pepper);
    }

    public function isValidFormat(string $pin): bool
    {
        $min = (int) static::config()->get('min_length');
        $max = (int) static::config()->get('max_length');

        return (bool) preg_match('/^\d{' . $min . ',' . $max . '}$/', $pin);
    }

    public function isTrivial(string $pin): bool
    {
        if (!static::config()->get('reject_trivial')) {
            return false;
        }
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        $ascending = '01234567890';
        $descending = '09876543210';

        return str_contains($ascending, $pin) || str_contains($descending, $pin);
    }
}
