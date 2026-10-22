<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Single source of "now" for the module. Respects DBDatetime::set_mock_now() (tests)
 * and an optional configured timezone (falls back to PHP's date.timezone).
 */
class Clock
{
    use Injectable;
    use Configurable;

    /** IANA timezone, e.g. "Europe/Sarajevo". Null = date_default_timezone_get(). */
    private static ?string $timezone = null;

    public function timezone(): DateTimeZone
    {
        $tz = static::config()->get('timezone');

        return new DateTimeZone(is_string($tz) && $tz !== '' ? $tz : date_default_timezone_get());
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . DBDatetime::now()->getTimestamp()))->setTimezone($this->timezone());
    }

    public function today(): DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }

    public function parseDate(?string $ymd): ?DateTimeImmutable
    {
        if ($ymd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $this->timezone());

        return $date !== false && $date->format('Y-m-d') === $ymd ? $date : null;
    }

    public function combine(string $ymd, ?string $time): DateTimeImmutable
    {
        $time = $time !== null && $time !== '' ? $time : '00:00:00';
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        return new DateTimeImmutable($ymd . ' ' . $time, $this->timezone());
    }

    /**
     * MySQL DATETIME string in local time, as SS stores it.
     */
    public function toDbDatetime(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone($this->timezone())->format('Y-m-d H:i:s');
    }
}
