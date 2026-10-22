<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Configurable shifts (2 or 3 per day, or any other layout):
 *
 * YouWillLikeIT\CareHome\Service\ShiftResolver:
 *   shifts:
 *     Day:   { label: 'Dnevna', start: '07:00', end: '19:00' }
 *     Night: { label: 'Noćna',  start: '19:00', end: '07:00' }
 *
 * A shift whose end <= start runs past midnight; it belongs to the date it starts on.
 */
class ShiftResolver
{
    use Injectable;
    use Configurable;

    private static array $shifts = [
        'Morning' => ['label' => 'Jutarnja', 'start' => '06:00', 'end' => '14:00'],
        'Afternoon' => ['label' => 'Popodnevna', 'start' => '14:00', 'end' => '22:00'],
        'Night' => ['label' => 'Noćna', 'start' => '22:00', 'end' => '06:00'],
    ];

    public function __construct(private readonly Clock $clock)
    {
    }

    /** @return array<string,string> code => label */
    public function options(): array
    {
        $options = [];
        foreach ($this->shifts() as $code => $shift) {
            $options[$code] = _t(__CLASS__ . '.SHIFT_' . strtoupper($code), (string) $shift['label']);
        }

        return $options;
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null
     */
    public function window(DateTimeImmutable $shiftDate, string $code): ?array
    {
        $shift = $this->shifts()[$code] ?? null;
        if (!$shift) {
            return null;
        }
        $ymd = $shiftDate->format('Y-m-d');
        $start = $this->clock->combine($ymd, $shift['start']);
        $end = $this->clock->combine($ymd, $shift['end']);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }

    /**
     * Shift running at the given moment.
     *
     * @return array{code:string,date:DateTimeImmutable}|null
     */
    public function current(?DateTimeImmutable $at = null): ?array
    {
        $at ??= $this->clock->now();
        $today = $at->setTime(0, 0);

        // a night shift started yesterday may still be running
        foreach ([$today, $today->modify('-1 day')] as $date) {
            foreach (array_keys($this->shifts()) as $code) {
                [$start, $end] = $this->window($date, $code);
                if ($at >= $start && $at < $end) {
                    return ['code' => $code, 'date' => $date];
                }
            }
        }

        return null;
    }

    /** @return array<string,array{label:string,start:string,end:string}> */
    private function shifts(): array
    {
        $valid = [];
        foreach ((array) static::config()->get('shifts') as $code => $shift) {
            if (!is_array($shift) || empty($shift['start']) || empty($shift['end'])) {
                continue;
            }
            $valid[(string) $code] = [
                'label' => (string) ($shift['label'] ?? $code),
                'start' => (string) $shift['start'],
                'end' => (string) $shift['end'],
            ];
        }

        return $valid;
    }
}
