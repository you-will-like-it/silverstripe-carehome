<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;
use YouWillLikeIT\CareHome\Model\Kitchen\PatientMealSelection;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Aggregated counts for the kitchen: per day, per meal, per menu option.
 */
class KitchenSummaryService
{
    use Injectable;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public function range(string $key): array
    {
        $today = $this->clock->today();

        return match ($key) {
            'today' => [$today, $today],
            'week' => [$today, $today->modify('+6 days')],
            default => [$today->modify('+1 day'), $today->modify('+1 day')],
        };
    }

    /**
     * @return ArrayList<ArrayData>
     */
    public function summary(DateTimeImmutable $from, DateTimeImmutable $to): ArrayList
    {
        $presentCount = Patient::get()
            ->filter(['Status' => Patient::STATUS_ACTIVE, 'IsTemporarilyAbsent' => false])
            ->exclude('BedID', 0)
            ->count();

        $days = ArrayList::create();
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $ymd = $day->format('Y-m-d');
            $meals = ArrayList::create();

            foreach (WeeklyMenuItem::mealTypeOptions() as $mealType => $mealLabel) {
                $counts = [];
                foreach (PatientMealSelection::get()->filter(['SelectedDate' => $ymd, 'MealType' => $mealType]) as $selection) {
                    $counts[(int) $selection->MenuItemID] = ($counts[(int) $selection->MenuItemID] ?? 0) + 1;
                }

                $options = ArrayList::create();
                $selected = 0;
                foreach (WeeklyMenuItem::get()->filter(['MenuDate' => $ymd, 'MealType' => $mealType])->sort('OptionName') as $item) {
                    $count = $counts[(int) $item->ID] ?? 0;
                    $selected += $count;
                    $options->push(ArrayData::create([
                        'OptionName' => $item->OptionName,
                        'Description' => $item->Description,
                        'Count' => $count,
                    ]));
                }

                $meals->push(ArrayData::create([
                    'MealType' => $mealType,
                    'Label' => $mealLabel,
                    'Options' => $options,
                    'Selected' => $selected,
                    // only meaningful for the current planning state
                    'Unselected' => max(0, $presentCount - $selected),
                    'HasMenu' => $options->count() > 0,
                ]));
            }

            /** @var DBDate $date */
            $date = DBField::create_field('Date', $ymd);
            $days->push(ArrayData::create([
                'Date' => $date,
                'Ymd' => $ymd,
                'Meals' => $meals,
            ]));
        }

        return $days;
    }

    /**
     * Present residents with special dietary needs (free text and/or tags), by room.
     *
     * @return ArrayList<ArrayData>
     */
    public function dietNotes(): ArrayList
    {
        $rows = [];
        $patients = Patient::get()
            ->filter(['Status' => Patient::STATUS_ACTIVE, 'IsTemporarilyAbsent' => false])
            ->exclude('BedID', 0);

        foreach ($patients as $patient) {
            $tags = implode(', ', $patient->DietaryTags()->column('Title'));
            $text = trim((string) $patient->DietaryRequirements);
            if ($tags === '' && $text === '') {
                continue;
            }
            $room = $patient->Bed()->Room();
            $rows[] = [
                'Name' => $patient->getFullName(),
                'Room' => $room->exists() ? $room->RoomNumber : '',
                'Tags' => $tags,
                'Requirements' => $text,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['Room'] . $a['Name'], $b['Room'] . $b['Name']));

        return ArrayList::create(array_map(static fn (array $row): ArrayData => ArrayData::create($row), $rows));
    }
}
