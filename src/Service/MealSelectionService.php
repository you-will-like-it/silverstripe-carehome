<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Model\Kitchen\PatientMealSelection;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Model\Patient\Patient;

/**
 * Meals are always chosen today for tomorrow (lunch + dinner, no breakfast).
 *
 * Diet filtering is optional: if a resident has DietaryTags, menu items marked
 * NotSuitableFor any of those tags are hidden. Without tags, all items are offered.
 */
class MealSelectionService
{
    use Injectable;
    use Configurable;

    /** "HH:MM" after which tomorrow's choice is locked, or null for no cutoff. */
    private static ?string $selection_cutoff = null;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function targetDate(): DateTimeImmutable
    {
        return $this->clock->today()->modify('+1 day');
    }

    public function isSelectionOpen(): bool
    {
        $cutoff = static::config()->get('selection_cutoff');
        if (!is_string($cutoff) || !preg_match('/^\d{2}:\d{2}$/', $cutoff)) {
            return true;
        }

        return $this->clock->now() < $this->clock->combine($this->clock->today()->format('Y-m-d'), $cutoff);
    }

    public function getCutoff(): ?string
    {
        $cutoff = static::config()->get('selection_cutoff');

        return is_string($cutoff) && $cutoff !== '' ? $cutoff : null;
    }

    /**
     * @return ArrayList<WeeklyMenuItem>
     */
    public function optionsFor(Patient $patient, DateTimeImmutable $date, string $mealType): ArrayList
    {
        $items = WeeklyMenuItem::get()->filter([
            'MenuDate' => $date->format('Y-m-d'),
            'MealType' => $mealType,
        ])->sort('OptionName');

        $patientTags = array_map('intval', $patient->DietaryTags()->column('ID'));
        $result = ArrayList::create();
        foreach ($items as $item) {
            if ($patientTags !== []) {
                $unsuitable = array_map('intval', $item->NotSuitableFor()->column('ID'));
                if (array_intersect($patientTags, $unsuitable) !== []) {
                    continue;
                }
            }
            $result->push($item);
        }

        return $result;
    }

    public function currentSelection(Patient $patient, DateTimeImmutable $date, string $mealType): ?PatientMealSelection
    {
        /** @var PatientMealSelection|null $selection */
        $selection = PatientMealSelection::get()->filter([
            'PatientID' => (int) $patient->ID,
            'SelectedDate' => $date->format('Y-m-d'),
            'MealType' => $mealType,
        ])->first();

        return $selection;
    }

    /**
     * @throws RuntimeException with a user-facing message
     */
    public function select(Patient $patient, string $mealType, int $menuItemId, Member $worker): PatientMealSelection
    {
        if (!$patient->isAvailableForCare()) {
            throw new RuntimeException(_t(__CLASS__ . '.NOT_AVAILABLE', 'Štićenik trenutno nije prisutan.'));
        }
        if (!$this->isSelectionOpen()) {
            throw new RuntimeException(_t(__CLASS__ . '.CLOSED', 'Izbor obroka za sutra je zaključen.'));
        }
        if (!array_key_exists($mealType, WeeklyMenuItem::mealTypeOptions())) {
            throw new RuntimeException(_t(__CLASS__ . '.INVALID_MEAL', 'Nepoznat obrok.'));
        }

        $date = $this->targetDate();
        $allowed = $this->optionsFor($patient, $date, $mealType)->find('ID', $menuItemId);
        if (!$allowed) {
            throw new RuntimeException(_t(__CLASS__ . '.INVALID_ITEM', 'Odabrani meni nije dostupan za ovog štićenika.'));
        }

        $selection = $this->currentSelection($patient, $date, $mealType) ?? PatientMealSelection::create();
        $selection->PatientID = (int) $patient->ID;
        $selection->MenuItemID = $menuItemId;
        $selection->RecordedByID = (int) $worker->ID;
        $selection->write();

        return $selection;
    }
}
