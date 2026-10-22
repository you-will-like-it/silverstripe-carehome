<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Model\Task;

use DateTimeInterface;

/**
 * ISO-8601 weekday helper (1 = Monday ... 7 = Sunday).
 * Stored on templates as a JSON array of strings, which is what CheckboxSetField writes
 * into a non-relational DB field (MultiSelectField::stringEncode()).
 */
final class DaysOfWeek
{
    /** @return array<int,string> */
    public static function options(): array
    {
        return [
            1 => _t(__CLASS__ . '.MON', 'Ponedjeljak'),
            2 => _t(__CLASS__ . '.TUE', 'Utorak'),
            3 => _t(__CLASS__ . '.WED', 'Srijeda'),
            4 => _t(__CLASS__ . '.THU', 'Četvrtak'),
            5 => _t(__CLASS__ . '.FRI', 'Petak'),
            6 => _t(__CLASS__ . '.SAT', 'Subota'),
            7 => _t(__CLASS__ . '.SUN', 'Nedjelja'),
        ];
    }

    /** @return array<int,string> */
    public static function shortOptions(): array
    {
        return [
            1 => _t(__CLASS__ . '.MON_SHORT', 'Pon'),
            2 => _t(__CLASS__ . '.TUE_SHORT', 'Uto'),
            3 => _t(__CLASS__ . '.WED_SHORT', 'Sri'),
            4 => _t(__CLASS__ . '.THU_SHORT', 'Čet'),
            5 => _t(__CLASS__ . '.FRI_SHORT', 'Pet'),
            6 => _t(__CLASS__ . '.SAT_SHORT', 'Sub'),
            7 => _t(__CLASS__ . '.SUN_SHORT', 'Ned'),
        ];
    }

    /**
     * @return list<int> sorted, unique, only 1..7
     */
    public static function parse(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            // tolerate legacy comma separated values
            $decoded = explode(',', $stored);
        }

        $days = [];
        foreach ($decoded as $value) {
            $day = (int) $value;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * @param list<int> $days
     */
    public static function encode(array $days): string
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $days),
            static fn (int $d): bool => $d >= 1 && $d <= 7
        )));
        sort($clean);

        return (string) json_encode(array_map('strval', $clean));
    }

    /**
     * @param list<int> $days
     */
    public static function matches(array $days, DateTimeInterface $date): bool
    {
        return in_array((int) $date->format('N'), $days, true);
    }

    /**
     * @param list<int> $days
     */
    public static function label(array $days): string
    {
        if (count($days) === 7) {
            return _t(__CLASS__ . '.EVERY_DAY', 'Svaki dan');
        }
        if ($days === [1, 2, 3, 4, 5]) {
            return _t(__CLASS__ . '.WEEKDAYS', 'Radnim danima');
        }
        $short = self::shortOptions();

        return implode(', ', array_map(static fn (int $d): string => $short[$d], $days));
    }
}
