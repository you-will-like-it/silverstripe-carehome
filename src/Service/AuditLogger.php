<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use Throwable;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;

/**
 * Writes AuditLogEntry rows. Failures are logged to the PSR logger and never break the
 * business operation that triggered them.
 */
class AuditLogger
{
    use Injectable;
    use Configurable;

    /** Never logged at all. */
    private static array $ignored_fields = [
        'LastEdited',
        'Created',
        'ClassName',
        'BedLock',
        'Version',
        'LastVisited',
        'NumVisit',
        'FailedLoginCount',
        'LockedOutUntil',
        'TempIDExpired',
        'AutoLoginExpired',
        'PasswordExpiry',
        // rotated on every CMS login, would flood the log
        'TempIDHash',
        'AutoLoginHash',
    ];

    /** Logged as "changed" with masked values. */
    private static array $masked_fields = [
        'Password',
        'Salt',
        'PasswordEncryption',
        'WorkerPinLookup',
    ];

    /** Max stored characters per value. */
    private static int $max_value_length = 2000;

    public function __construct(private readonly AuditContext $context)
    {
    }

    /**
     * @param array<string,array{before:mixed,after:mixed}> $changes
     */
    public function logObject(string $action, DataObject $object, array $changes = []): void
    {
        if ($this->context->isSuppressed()) {
            return;
        }
        $clean = $this->sanitizeChanges($changes);
        if ($action === AuditLogEntry::ACTION_UPDATE && $clean === []) {
            return;
        }

        $title = '';
        try {
            $title = (string) $object->getTitle();
        } catch (Throwable) {
            // titles may depend on relations already deleted
        }

        $this->write([
            'Action' => $action,
            'ObjectClass' => $object::class,
            'ObjectID' => (int) ($object->ID ?: $object->OldID),
            'ObjectTitle' => mb_substr($title, 0, 255),
            'Changes' => $clean === [] ? null : (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }

    /**
     * Non-CRUD events (logins, failed PIN attempts, exports).
     *
     * @param array<string,mixed> $context
     */
    public function logEvent(string $action, string $title, ?Member $actor = null, array $context = []): void
    {
        if ($this->context->isSuppressed()) {
            return;
        }
        $this->write([
            'Action' => $action,
            'ObjectClass' => $actor ? Member::class : '',
            'ObjectID' => $actor ? (int) $actor->ID : 0,
            'ObjectTitle' => mb_substr($title, 0, 255),
            'Changes' => $context === [] ? null : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ], $actor);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function write(array $data, ?Member $actorOverride = null): void
    {
        $actor = $actorOverride ?? $this->context->getActor();

        $this->context->suppress(function () use ($data, $actor): void {
            try {
                $entry = AuditLogEntry::create($data);
                $entry->ActorID = $actor ? (int) $actor->ID : 0;
                $entry->ActorName = $actor ? mb_substr(trim((string) $actor->getName()), 0, 255) : '';
                $entry->Channel = $this->context->getChannel();
                $entry->IPAddress = mb_substr($this->context->getIP(), 0, 45);
                $entry->write();
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->error(
                    'CareHome audit log write failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        });
    }

    /**
     * @param array<string,array{before:mixed,after:mixed}> $changes
     * @return array<string,array{before:mixed,after:mixed}>
     */
    private function sanitizeChanges(array $changes): array
    {
        $ignored = array_flip((array) static::config()->get('ignored_fields'));
        $masked = array_flip((array) static::config()->get('masked_fields'));
        $max = (int) static::config()->get('max_value_length');

        $clean = [];
        foreach ($changes as $field => $change) {
            if (isset($ignored[$field])) {
                continue;
            }
            $before = $this->normalize($change['before'] ?? null);
            $after = $this->normalize($change['after'] ?? null);
            if ($before === $after) {
                continue;
            }
            if (isset($masked[$field])) {
                $clean[$field] = ['before' => $before === null ? null : '***', 'after' => $after === null ? null : '***'];
                continue;
            }
            $clean[$field] = [
                'before' => is_string($before) ? mb_substr($before, 0, $max) : $before,
                'after' => is_string($after) ? mb_substr($after, 0, $max) : $after,
            ];
        }

        return $clean;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            if (method_exists($value, 'getValue')) {
                $value = $value->getValue();
            } else {
                return null;
            }
        }
        if ($value === '') {
            return null;
        }

        return is_scalar($value) || $value === null ? (is_int($value) || is_float($value) ? (string) $value : $value) : null;
    }
}
