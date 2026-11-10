<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Dev;

use DateTimeImmutable;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Facility\FacilityRoom;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Kitchen\PatientMealSelection;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Model\Note\PatientNote;
use YouWillLikeIT\CareHome\Model\Note\ShiftHandoverLog;
use YouWillLikeIT\CareHome\Model\Patient\BedAssignment;
use YouWillLikeIT\CareHome\Model\Patient\DietaryTag;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\GlobalTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\IndividualTaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\PatientGlobalTask;
use YouWillLikeIT\CareHome\Model\Task\TaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\VitalParameterDefinition;
use YouWillLikeIT\CareHome\Model\Task\VitalReading;
use YouWillLikeIT\CareHome\Security\PinHasher;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\MealSelectionService;
use YouWillLikeIT\CareHome\Service\ShiftResolver;
use YouWillLikeIT\CareHome\Service\TaskGenerator;

/**
 * Presentation data: facility, workers with PINs, residents, templates, menus,
 * a filled history (tasks, vitals, notes, meals, handovers) and future tasks/menus.
 * All dates are relative to "today", so re-running refreshes the demo to the current date.
 * Every root record is registered in DemoSeedRecord; purge() removes demo data only.
 */
class DemoSeeder
{
    use Injectable;

    /** Demo e-mail domain (reserved TLD, never deliverable). */
    public const string EMAIL_DOMAIN = 'carehome.demo';

    /** @var list<string> */
    private array $log = [];

    /** @var array<string,FacilityZone> leaf zones by key */
    private array $zones = [];

    /** @var array<string,Member> */
    private array $workers = [];

    /** @var array<string,DietaryTag> */
    private array $tags = [];

    /** @var array<string,VitalParameterDefinition> */
    private array $vitals = [];

    /** @var array<string,GlobalTaskTemplate> */
    private array $globals = [];

    /** @var list<array{patient:Patient,zone:string,from:string,until:string,diabetic:bool,base:array<string,float>}> */
    private array $residents = [];

    private DateTimeImmutable $now;

    private DateTimeImmutable $today;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @return list<string> log lines
     */
    public function purge(): array
    {
        $this->log = [];
        AuditContext::singleton()->suppress(fn () => DB::get_conn()->withTransaction(fn () => $this->doPurge()));

        return $this->log;
    }

    /**
     * @return list<string> log lines
     */
    public function seed(int $futureDays, int $historyDays): array
    {
        $this->log = [];
        $this->now = $this->clock->now();
        $this->today = $this->clock->today();
        mt_srand(20261110);

        Config::nest();
        // generate future tasks for the whole demo period, not only the regular cron horizon
        Config::modify()->set(TaskGenerator::class, 'horizon_days', $futureDays);

        try {
            AuditContext::singleton()->suppress(function () use ($futureDays, $historyDays): void {
                DB::get_conn()->withTransaction(function () use ($futureDays, $historyDays): void {
                    $this->doPurge();
                    $this->seedTags();
                    $this->seedVitalDefinitions();
                    $this->seedFacility();
                    $this->seedWorkers();
                    $this->seedGlobalTemplates();
                    $this->seedMenus($historyDays, $futureDays);
                    $this->seedResidents($historyDays);
                    $this->seedHistory($historyDays);
                    $this->seedNotes($historyDays);
                    $this->seedMeals($historyDays);
                    $this->seedHandovers(min(7, $historyDays));
                    $this->applyFinalStatuses();
                });
            });
        } finally {
            Config::unnest();
        }

        return $this->log;
    }

    /* ================================================================ purge */

    private function doPurge(): void
    {
        $patientIds = DemoSeedRecord::idsOf(Patient::class);

        // residents first: template/link deletes then find no resident to resync
        foreach (Patient::get()->filter('ID', $patientIds ?: [0]) as $patient) {
            $patient->DietaryTags()->removeAll();
            $patient->delete();
        }
        if ($patientIds) {
            foreach ([
                DailyTask::class, VitalReading::class, PatientNote::class, PatientMealSelection::class,
                BedAssignment::class, PatientGlobalTask::class, IndividualTaskTemplate::class,
            ] as $class) {
                foreach (DataObject::get($class)->filter('PatientID', $patientIds) as $record) {
                    $record->delete();
                }
            }
        }

        foreach (WeeklyMenuItem::get()->filter('ID', DemoSeedRecord::idsOf(WeeklyMenuItem::class) ?: [0]) as $item) {
            foreach ($item->Selections() as $selection) {
                $selection->delete();
            }
            $item->NotSuitableFor()->removeAll();
            $item->delete();
        }

        // remaining roots, newest first (children before parents)
        $order = [
            ShiftHandoverLog::class, GlobalTaskTemplate::class, FacilityBed::class, FacilityRoom::class,
            FacilityZone::class, Member::class, DietaryTag::class, VitalParameterDefinition::class,
        ];
        $removed = count($patientIds);
        foreach ($order as $class) {
            $entries = DemoSeedRecord::get()->filter('ObjectClass', $class)->sort('ID', 'DESC');
            foreach ($entries as $entry) {
                $record = DataObject::get($class)->byID((int) $entry->ObjectID);
                if ($record) {
                    // demo residents/menus are gone already: any remaining use is real data, keep the record
                    if ($record instanceof DietaryTag
                        && ($record->Patients()->exists() || $record->UnsuitableMenuItems()->exists())
                    ) {
                        continue;
                    }
                    if ($record instanceof VitalParameterDefinition && $record->Readings()->exists()) {
                        continue;
                    }
                    $record->delete();
                    $removed++;
                }
            }
        }

        DB::prepared_query('DELETE FROM "CareHome_DemoSeedRecord"', []);
        $this->log[] = sprintf('Uklonjeni prethodni demo podaci (%d glavnih zapisa).', $removed);
    }

    /* ============================================================ master data */

    private function seedTags(): void
    {
        foreach ([
            'Dijabetes' => 'Bez dodanog šećera, ograničeni ugljikohidrati.',
            'Miksana hrana' => 'Otežano gutanje – hrana se usitnjava.',
            'Bez laktoze' => 'Netolerancija na laktozu.',
        ] as $title => $description) {
            $tag = DietaryTag::get()->filter('Title', $title)->first();
            if (!$tag) {
                $tag = DietaryTag::create(['Title' => $title, 'Description' => $description]);
                $tag->write();
                DemoSeedRecord::register($tag);
            }
            $this->tags[$title] = $tag;
        }
    }

    private function seedVitalDefinitions(): void
    {
        $defaults = (array) Config::inst()->get(VitalParameterDefinition::class, 'default_definitions');
        foreach ($defaults as $definition) {
            $record = VitalParameterDefinition::get()->filter('Code', $definition['Code'])->first();
            if (!$record) {
                $record = VitalParameterDefinition::create($definition);
                $record->IsActive = true;
                $record->write();
                DemoSeedRecord::register($record);
            }
            $this->vitals[$definition['Code']] = $record;
        }
    }

    private function seedFacility(): void
    {
        $layout = [
            'Dom „Sunčana bašta" – Zgrada A' => [
                'A1' => ['Sprat 1', ['101' => 2, '102' => 2, '103' => 1, '104' => 2]],
                'A2' => ['Sprat 2', ['201' => 2, '202' => 2, '203' => 2]],
            ],
            'Dom „Sunčana bašta" – Zgrada B' => [
                'B0' => ['Prizemlje', ['01' => 2, '02' => 1, '03' => 2]],
            ],
        ];
        $bedNames = [1 => ['Krevet 1'], 2 => ['Krevet 1 – do prozora', 'Krevet 2 – do vrata']];

        $sort = 10;
        $beds = 0;
        foreach ($layout as $buildingTitle => $floors) {
            $building = $this->createRoot(FacilityZone::class, [
                'Title' => $buildingTitle,
                'Description' => 'Demo podaci',
                'Sort' => $sort += 10,
            ]);
            foreach ($floors as $key => [$floorTitle, $rooms]) {
                $floor = $this->createRoot(FacilityZone::class, [
                    'Title' => $floorTitle,
                    'ParentID' => (int) $building->ID,
                    'Sort' => $sort += 10,
                ]);
                $this->zones[$key] = $floor;
                foreach ($rooms as $number => $bedCount) {
                    $room = $this->createRoot(FacilityRoom::class, [
                        'RoomNumber' => (string) $number,
                        'FloorInfo' => $floorTitle,
                        'ZoneID' => (int) $floor->ID,
                    ]);
                    foreach ($bedNames[$bedCount] as $label) {
                        $this->createRoot(FacilityBed::class, ['BedLabel' => $label, 'RoomID' => (int) $room->ID]);
                        $beds++;
                    }
                }
            }
        }
        $this->log[] = sprintf('Smještaj: 2 zgrade, 3 sprata, 10 soba, %d kreveta.', $beds);
    }

    private function seedWorkers(): void
    {
        $definitions = [
            'amra' => ['Amra', 'Selimović', MemberCareExtension::ROLE_CAREWORKER, '2580', ['A1', 'A2']],
            'nermin' => ['Nermin', 'Hadžić', MemberCareExtension::ROLE_CAREWORKER, '3691', ['A1', 'A2', 'B0']],
            'senad' => ['Senad', 'Mujić', MemberCareExtension::ROLE_CAREWORKER, '4702', ['A1', 'A2', 'B0']],
            'lejla' => ['Lejla', 'Đurić', MemberCareExtension::ROLE_CAREWORKER, '6914', ['B0']],
            'jasmina' => ['Jasmina', 'Kurtović', MemberCareExtension::ROLE_KITCHEN, '5813', []],
            'admin' => ['Demo', 'Administrator', MemberCareExtension::ROLE_ADMIN, '7924', []],
        ];

        $hasher = PinHasher::singleton();
        $pinsEnabled = $hasher->isConfigured();
        if (!$pinsEnabled) {
            $this->log[] = 'UPOZORENJE: CAREHOME_PIN_PEPPER nije postavljen u .env – radnici su kreirani bez PIN-a.';
        }
        if (!Group::get()->filter('Code', 'carehome-careworkers')->exists()) {
            $this->log[] = 'UPOZORENJE: grupe modula ne postoje – pokrenite db:build prije seeda.';
        }

        $lines = [];
        foreach ($definitions as $key => [$first, $last, $role, $pin, $zoneKeys]) {
            $member = Member::create();
            $member->FirstName = $first;
            $member->Surname = $last;
            $member->Email = $key . '@' . self::EMAIL_DOMAIN;
            $member->WorkerRole = $role;
            $member->PortalEnabled = true;

            $pinSet = false;
            if ($pinsEnabled) {
                $taken = Member::get()->filter('WorkerPinLookup', $hasher->lookup($pin))->exists();
                if ($taken) {
                    $this->log[] = "UPOZORENJE: PIN $pin već koristi postojeći korisnik – $first $last nema PIN.";
                } else {
                    $member->setField('NewWorkerPin', $pin);
                    $pinSet = true;
                }
            }
            $member->write();
            DemoSeedRecord::register($member);

            foreach ($zoneKeys as $zoneKey) {
                $member->AssignedZones()->add($this->zones[$zoneKey]);
            }
            $this->workers[$key] = $member;
            $lines[] = sprintf('  %-22s %-14s PIN %s', "$first $last", MemberCareExtension::roleOptions()[$role], $pinSet ? $pin : '—');
        }
        $this->log[] = 'Radnici:';
        array_push($this->log, ...$lines);
    }

    private function seedGlobalTemplates(): void
    {
        $every = [1, 2, 3, 4, 5, 6, 7];
        $definitions = [
            'bp' => ['Jutarnje mjerenje pritiska i pulsa', TaskTemplate::CATEGORY_VITALS, '08:00', $every, ['BP_SYS', 'BP_DIA', 'PULSE'], 'Mjeriti u sjedećem položaju, nakon 5 min mirovanja.'],
            'glucose' => ['Mjerenje šećera natašte', TaskTemplate::CATEGORY_VITALS, '06:30', $every, ['GLUCOSE'], 'Prije doručka.'],
            'temp' => ['Večernja temperatura i saturacija', TaskTemplate::CATEGORY_VITALS, '18:00', $every, ['TEMP', 'SPO2'], null],
            'weight' => ['Vaganje', TaskTemplate::CATEGORY_VITALS, '07:30', [1], ['WEIGHT'], 'Natašte, ista vaga.'],
            'bath' => ['Kupanje', TaskTemplate::CATEGORY_HYGIENE, '10:00', [2, 5], [], 'Uz pomoć dva radnika.'],
            'care' => ['Večernja njega i priprema za spavanje', TaskTemplate::CATEGORY_HYGIENE, '20:30', $every, [], null],
            'fluids' => ['Unos tečnosti – provjera', TaskTemplate::CATEGORY_NUTRITION, '15:00', $every, [], 'Ponuditi čaj ili vodu, zabilježiti ako odbija.'],
        ];

        foreach ($definitions as $key => [$title, $category, $time, $days, $codes, $instructions]) {
            $template = GlobalTaskTemplate::create();
            $template->Title = $title;
            $template->Category = $category;
            $template->ScheduledTime = $time . ':00';
            $template->DaysOfWeek = (string) json_encode(array_map('strval', $days));
            $template->Instructions = $instructions;
            $template->IsActive = true;
            if ($codes) {
                $template->VitalParameterIDs = (string) json_encode(array_map(
                    fn (string $code): string => (string) $this->vitals[$code]->ID,
                    $codes
                ));
            }
            $template->write();
            DemoSeedRecord::register($template);
            $this->globals[$key] = $template;
        }
        $this->log[] = sprintf('Globalni šabloni zadataka: %d.', count($definitions));
    }

    private function seedMenus(int $historyDays, int $futureDays): void
    {
        $lunches = [
            ['Pileći file u sosu', 'Pire krompir, salata od mrkve'],
            ['Grah sa suhim mesom', 'Kukuruzni hljeb'],
            ['Musaka od krompira', 'Kiselo mlijeko'],
            ['Oslić na žaru', 'Blitva s krompirom'],
            ['Sarma', 'Pire krompir'],
            ['Juneći gulaš', 'Domaći njoki'],
            ['Punjene paprike', 'Pire krompir'],
            ['Pečena piletina', 'Riža s povrćem, salata'],
            ['Bosanski lonac', 'Hljeb'],
            ['Ćufte u paradajz sosu', 'Pire krompir'],
        ];
        $lighter = [
            ['Varivo od mahuna', 'Bez mesa'],
            ['Tjestenina sa sirom', 'Zelena salata'],
            ['Pita krompiruša', 'Jogurt'],
            ['Rižoto od povrća', 'Salata od kupusa'],
            ['Riblji štapići', 'Krompir salata'],
        ];
        $dinners = [
            ['Palačinke sa sirom', 'Voćni čaj'],
            ['Kajgana sa sirom', 'Integralni hljeb, paradajz'],
            ['Griz na mlijeku', 'Kakao posip'],
            ['Pileća supa s rezancima', 'Hljeb'],
            ['Pita zeljanica', 'Kiselo mlijeko'],
            ['Hurmašice', 'Voćni čaj'],
            ['Varivo od graška', 'Hljeb'],
        ];
        $diabetic = [
            ['Teletina na žaru', 'Kuhano povrće'],
            ['Pileća prsa na žaru', 'Brokula, karfiol'],
            ['Riba na pari', 'Blitva'],
            ['Pileća supa', 'Integralni hljeb'],
            ['Omlet sa povrćem', 'Integralni hljeb'],
            ['Posni sir i povrće', 'Raženi hljeb'],
        ];
        $sweet = ['Palačinke sa sirom', 'Hurmašice', 'Griz na mlijeku'];
        $hardToSwallow = ['Grah sa suhim mesom', 'Sarma', 'Punjene paprike', 'Bosanski lonac'];
        $dairy = ['Musaka od krompira', 'Tjestenina sa sirom', 'Palačinke sa sirom', 'Kajgana sa sirom', 'Griz na mlijeku', 'Pita zeljanica'];

        $count = 0;
        for ($offset = -$historyDays; $offset <= $futureDays; $offset++) {
            $ymd = $this->today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
            $i = $offset + 1000;
            $options = [
                WeeklyMenuItem::MEAL_LUNCH => [
                    ['Meni A – ' . $lunches[$i % count($lunches)][0], $lunches[$i % count($lunches)][1]],
                    ['Meni B – ' . $lighter[$i % count($lighter)][0], $lighter[$i % count($lighter)][1]],
                    ['Meni C – Dijabetički: ' . $diabetic[$i % count($diabetic)][0], $diabetic[$i % count($diabetic)][1]],
                ],
                WeeklyMenuItem::MEAL_DINNER => [
                    ['Meni A – ' . $dinners[$i % count($dinners)][0], $dinners[$i % count($dinners)][1]],
                    ['Meni B – ' . $dinners[($i + 3) % count($dinners)][0], $dinners[($i + 3) % count($dinners)][1]],
                    ['Meni C – Dijabetički: ' . $diabetic[($i + 2) % count($diabetic)][0], $diabetic[($i + 2) % count($diabetic)][1]],
                ],
            ];
            foreach ($options as $mealType => $list) {
                foreach ($list as [$name, $description]) {
                    $item = $this->createRoot(WeeklyMenuItem::class, [
                        'MenuDate' => $ymd,
                        'MealType' => $mealType,
                        'OptionName' => $name,
                        'Description' => $description,
                    ]);
                    $dish = preg_replace('/^Meni [ABC] – /', '', $name);
                    if (in_array($dish, $sweet, true) || in_array($dish, array_column($lunches, 0), true)) {
                        // regular lunches and sweet dinners are not for diabetics
                        $item->NotSuitableFor()->add($this->tags['Dijabetes']);
                    }
                    if (in_array($dish, $hardToSwallow, true)) {
                        $item->NotSuitableFor()->add($this->tags['Miksana hrana']);
                    }
                    if (in_array($dish, $dairy, true)) {
                        $item->NotSuitableFor()->add($this->tags['Bez laktoze']);
                    }
                    $count++;
                }
            }
        }
        $this->log[] = sprintf('Jelovnik: %d stavki (%d dana unazad, %d dana unaprijed).', $count, $historyDays, $futureDays);
    }

    /* ============================================================== residents */

    private function seedResidents(int $historyDays): void
    {
        // first, last, birth (Y-m-d), female, zone, alerts, diet text, tags, therapies, globals
        $people = [
            ['Fatima', 'Hodžić', '1942-03-14', true, 'A1', "Alergija na penicilin\nDijabetes tip 2, hipertenzija", 'Bez dodanog šećera, mekana hrana.', ['Dijabetes'], ['metformin', 'ramipril'], ['bp', 'glucose', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Mirsad', 'Begić', '1938-11-02', false, 'A1', "Demencija (umjerena), sklonost padovima", 'Usitnjena hrana.', ['Miksana hrana'], ['donepezil', 'atorvastatin'], ['bp', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Ljubica', 'Petrović', '1945-06-21', true, 'A1', "Srčana insuficijencija", 'Ograničen unos soli.', [], ['furosemid', 'bisoprolol'], ['bp', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Hasan', 'Mehmedović', '1940-01-30', false, 'A1', "HOBP", '', [], ['inhaler'], ['bp', 'temp', 'weight', 'bath', 'care']],
            ['Zora', 'Kovačević', '1936-09-08', true, 'A1', "Dijabetes tip 2 (inzulin)\nAlergija na jaja", 'Bez jaja.', ['Dijabetes'], ['insulin', 'metformin'], ['bp', 'glucose', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Ibrahim', 'Delić', '1944-12-17', false, 'A1', "Stanje nakon moždanog udara", 'Otežano gutanje.', ['Miksana hrana'], ['aspirin', 'ramipril_change'], ['bp', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Marija', 'Jurić', '1941-04-03', true, 'A2', "Glaukom", '', ['Bez laktoze'], ['timolol'], ['bp', 'weight', 'bath', 'care']],
            ['Mehmed', 'Salihović', '1939-08-25', false, 'A2', "Hipertenzija, artroza koljena", '', [], ['amlodipin', 'paracetamol'], ['bp', 'weight', 'bath', 'care', 'fluids']],
            ['Dragica', 'Nikolić', '1947-02-11', true, 'A2', "Osteoporoza", '', [], ['calcium'], ['bp', 'weight', 'bath', 'care']],
            ['Safet', 'Omerović', '1935-10-19', false, 'A2', "Dijabetes tip 2, oštećenje vida", 'Bez dodanog šećera.', ['Dijabetes'], ['metformin', 'atorvastatin'], ['bp', 'glucose', 'temp', 'weight', 'bath', 'care']],
            ['Ana', 'Marić', '1943-07-07', true, 'A2', "Infekcija urinarnog trakta (terapija u toku)", '', [], ['cipro'], ['bp', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Ramiza', 'Kadić', '1937-05-28', true, 'B0', "Alzheimerova bolest", 'Usitnjena hrana, hraniti uz pomoć.', ['Miksana hrana'], ['donepezil'], ['bp', 'temp', 'weight', 'bath', 'care', 'fluids']],
            ['Milan', 'Stojanović', '1940-03-09', false, 'B0', "Parkinsonova bolest", '', [], ['levodopa'], ['bp', 'weight', 'bath', 'care']],
            ['Emina', 'Bašić', '1946-11-23', true, 'B0', "Hipotireoza", '', ['Bez laktoze'], ['levotiroksin'], ['bp', 'weight', 'bath', 'care']],
            // index 14: discharged 5 days ago; index 3 becomes temporarily absent 2 days ago
            ['Josip', 'Barišić', '1941-01-15', false, 'B0', "Stanje nakon operacije kuka", '', [], ['paracetamol'], ['bp', 'weight', 'bath', 'care']],
        ];

        $freeBeds = [];
        foreach (FacilityBed::get()->filter('ID', DemoSeedRecord::idsOf(FacilityBed::class) ?: [0])->sort('ID') as $bed) {
            $freeBeds[(int) $bed->Room()->ZoneID][] = $bed;
        }

        $earliest = $this->today->modify('-' . $historyDays . ' days');
        foreach ($people as $index => [$first, $last, $dob, $female, $zoneKey, $alerts, $dietText, $tagNames, $therapies, $globalKeys]) {
            $bed = array_shift($freeBeds[(int) $this->zones[$zoneKey]->ID]);
            $admission = $this->today->modify('-' . mt_rand(40, 700) . ' days');

            $patient = Patient::create();
            $patient->FirstName = $first;
            $patient->LastName = $last;
            $patient->DateOfBirth = $dob;
            $patient->JMBG = self::jmbg($dob, $female, $index);
            $patient->AdmissionDate = $admission->format('Y-m-d');
            $patient->MedicalAlerts = $alerts;
            $patient->DietaryRequirements = $dietText ?: null;
            $patient->AdminNotes = '<p>Prijem uz dokumentaciju porodičnog ljekara. Porodica obavještava o posjetama telefonom.</p>';
            $patient->EmergencyContactName = ($female ? 'Edin ' : 'Selma ') . $last;
            $patient->EmergencyContactPhone = sprintf('06%d %03d %03d', mt_rand(1, 3), mt_rand(100, 999), mt_rand(100, 999));
            $patient->EmergencyContactRelation = $female ? 'sin' : 'kćerka';
            $patient->Status = Patient::STATUS_ACTIVE;
            $patient->BedID = (int) $bed->ID;
            $patient->write();
            DemoSeedRecord::register($patient);

            foreach ($tagNames as $tagName) {
                $patient->DietaryTags()->add($this->tags[$tagName]);
            }
            foreach ($therapies as $therapyKey) {
                $this->createTherapy($patient, $therapyKey, $earliest);
            }
            foreach ($globalKeys as $globalKey) {
                $patient->AssignedGlobalTasks()->add($this->globals[$globalKey]);
            }

            $from = $earliest->format('Y-m-d');
            $until = $this->today->format('Y-m-d');
            if ($index === 14) {
                $until = $this->today->modify('-6 days')->format('Y-m-d');
            } elseif ($index === 3) {
                $until = $this->today->modify('-3 days')->format('Y-m-d');
            }

            $diabetic = in_array('Dijabetes', $tagNames, true);
            $this->residents[] = [
                'patient' => $patient,
                'zone' => $zoneKey,
                'from' => $from,
                'until' => $until,
                'diabetic' => $diabetic,
                'base' => [
                    'BP_SYS' => (float) mt_rand(122, 148),
                    'BP_DIA' => (float) mt_rand(72, 88),
                    'PULSE' => (float) mt_rand(64, 84),
                    'TEMP' => mt_rand(364, 369) / 10,
                    'SPO2' => (float) mt_rand(94, 98),
                    'GLUCOSE' => $diabetic ? mt_rand(62, 88) / 10 : mt_rand(48, 58) / 10,
                    'WEIGHT' => (float) mt_rand(52, 88),
                ],
            ];
        }

        // one-off appointments in the coming weeks
        $this->createOneOff($this->residents[2]['patient'], 'Kontrola kod kardiologa (KCUS)', '+6 days', '10:30', 'Pratnja: Nermin. Ponijeti nalaze.');
        $this->createOneOff($this->residents[0]['patient'], 'Vađenje krvi – HbA1c', '+2 days', '07:15', 'Natašte.');
        $this->createOneOff($this->residents[9]['patient'], 'Oftalmolog – kontrola', '+13 days', '09:00', null);

        $this->log[] = sprintf('Štićenici: %d (1 privremeno odsutan, 1 otpušten).', count($people));
    }

    private function createTherapy(Patient $patient, string $key, DateTimeImmutable $earliest): void
    {
        $every = [1, 2, 3, 4, 5, 6, 7];
        $start = $earliest->modify('-30 days')->format('Y-m-d');
        $catalog = [
            'metformin' => [['Jutarnja terapija – Metformin', 'Metformin 500 mg', '1 tableta', 'Oral', '07:00', $every, 'Uz doručak.'], ['Večernja terapija – Metformin', 'Metformin 500 mg', '1 tableta', 'Oral', '19:00', $every, 'Uz večeru.']],
            'ramipril' => [['Terapija za pritisak', 'Ramipril 5 mg', '1 tableta', 'Oral', '08:00', $every, null]],
            'bisoprolol' => [['Terapija za srce', 'Bisoprolol 2,5 mg', '1 tableta', 'Oral', '08:00', $every, 'Ne davati ako je puls ispod 55.']],
            'furosemid' => [['Diuretik', 'Furosemid 40 mg', '1 tableta', 'Oral', '07:00', [1, 3, 5], null]],
            'atorvastatin' => [['Večernja terapija – statin', 'Atorvastatin 20 mg', '1 tableta', 'Oral', '20:00', $every, null]],
            'insulin' => [['Inzulin – večernja doza', 'Inzulin glargin 100 IJ/ml', '12 IJ', 'Subcutaneous', '21:00', $every, 'Mijenjati mjesto uboda.']],
            'donepezil' => [['Večernja terapija – Donepezil', 'Donepezil 5 mg', '1 tableta', 'Oral', '20:00', $every, null]],
            'inhaler' => [['Inhalacija', 'Tiotropij 18 mcg', '1 udah', 'Inhalation', '08:00', $every, null]],
            'aspirin' => [['Antiagregacijska terapija', 'Acetilsalicilna kiselina 100 mg', '1 tableta', 'Oral', '13:00', $every, 'Poslije ručka.']],
            'timolol' => [['Kapi za oči – jutro', 'Timolol 0,5 %', '1 kap u oba oka', 'Ophthalmic', '08:00', $every, null], ['Kapi za oči – veče', 'Timolol 0,5 %', '1 kap u oba oka', 'Ophthalmic', '20:00', $every, null]],
            'amlodipin' => [['Terapija za pritisak', 'Amlodipin 5 mg', '1 tableta', 'Oral', '08:00', $every, null]],
            'paracetamol' => [['Analgetik', 'Paracetamol 500 mg', '1 tableta', 'Oral', '13:00', $every, 'Po potrebi ponuditi i uveče.']],
            'calcium' => [['Kalcij i vitamin D', 'Kalcij 500 mg + D3 400 IJ', '1 šumeća tableta', 'Oral', '13:00', $every, null]],
            'levodopa' => [['Terapija – Levodopa (jutro)', 'Levodopa/benserazid 100/25 mg', '1 kapsula', 'Oral', '07:00', $every, '30 min prije obroka.'], ['Terapija – Levodopa (podne)', 'Levodopa/benserazid 100/25 mg', '1 kapsula', 'Oral', '12:00', $every, null], ['Terapija – Levodopa (veče)', 'Levodopa/benserazid 100/25 mg', '1 kapsula', 'Oral', '18:00', $every, null]],
            'levotiroksin' => [['Jutarnja terapija – štitna žlijezda', 'Levotiroksin 50 mcg', '1 tableta', 'Oral', '06:30', $every, 'Natašte, 30 min prije doručka.']],
        ];

        // antibiotic course: started 4 days ago, ends in 3 days
        if ($key === 'cipro') {
            foreach (['08:00' => 'jutro', '20:00' => 'veče'] as $time => $label) {
                $this->writeIndividual($patient, "Antibiotik – $label", 'Ciprofloksacin 500 mg', '1 tableta', 'Oral', $time, $every,
                    $this->today->modify('-4 days')->format('Y-m-d'), $this->today->modify('+3 days')->format('Y-m-d'), 'Uz punu čašu vode.');
            }

            return;
        }
        // therapy change "from tomorrow": 5 mg until today, 10 mg from tomorrow
        if ($key === 'ramipril_change') {
            $this->writeIndividual($patient, 'Terapija za pritisak', 'Ramipril 5 mg', '1 tableta', 'Oral', '08:00', $every,
                $start, $this->today->format('Y-m-d'), null);
            $this->writeIndividual($patient, 'Terapija za pritisak', 'Ramipril 10 mg', '1 tableta', 'Oral', '08:00', $every,
                $this->today->modify('+1 day')->format('Y-m-d'), null, 'Povećana doza po nalazu kardiologa.');

            return;
        }

        foreach ($catalog[$key] as [$title, $medication, $dose, $route, $time, $days, $instructions]) {
            $this->writeIndividual($patient, $title, $medication, $dose, $route, $time, $days, $start, null, $instructions);
        }
    }

    /**
     * @param list<int> $days
     */
    private function writeIndividual(Patient $patient, string $title, string $medication, string $dose, string $route, string $time, array $days, string $start, ?string $end, ?string $instructions): void
    {
        $template = IndividualTaskTemplate::create();
        $template->PatientID = (int) $patient->ID;
        $template->Title = $title;
        $template->Category = TaskTemplate::CATEGORY_THERAPY;
        $template->Medication = $medication;
        $template->Dose = $dose;
        $template->Route = $route;
        $template->ScheduledTime = $time . ':00';
        $template->DaysOfWeek = (string) json_encode(array_map('strval', $days));
        $template->StartDate = $start;
        $template->EndDate = $end;
        $template->Instructions = $instructions;
        $template->IsActive = true;
        $template->write();
    }

    private function createOneOff(Patient $patient, string $title, string $relative, string $time, ?string $instructions): void
    {
        $template = IndividualTaskTemplate::create();
        $template->PatientID = (int) $patient->ID;
        $template->Title = $title;
        $template->Category = TaskTemplate::CATEGORY_OTHER;
        $template->ScheduledTime = $time . ':00';
        $template->IsOneOff = true;
        $template->StartDate = $this->today->modify($relative)->format('Y-m-d');
        $template->Instructions = $instructions;
        $template->IsActive = true;
        $template->write();
    }

    /* =============================================================== history */

    private function seedHistory(int $historyDays): void
    {
        $skipReasons = [
            'Odbio/la terapiju, ponovo ponuđeno nakon 30 min – opet odbio/la.',
            'Spavao/la, nije buđen/a po dogovoru s ljekarom.',
            'Na pregledu van doma u to vrijeme.',
            'Mučnina, obaviještena dežurna sestra.',
            'Posjeta porodice, urađeno kasnije u sljedećem terminu.',
        ];
        $tasks = 0;
        $readings = 0;

        foreach ($this->residents as $resident) {
            /** @var Patient $patient */
            $patient = $resident['patient'];
            $templates = array_merge(
                iterator_to_array($patient->AssignedGlobalTasks(), false),
                iterator_to_array(IndividualTaskTemplate::get()->filter('PatientID', (int) $patient->ID), false)
            );

            for ($offset = $historyDays; $offset >= 0; $offset--) {
                $day = $this->today->modify('-' . $offset . ' days');
                $ymd = $day->format('Y-m-d');
                if ($ymd < $resident['from'] || $ymd > $resident['until']) {
                    continue;
                }

                foreach ($templates as $template) {
                    if (!$template->occursOn($day)) {
                        continue;
                    }
                    $time = substr((string) $template->ScheduledTime, 0, 8);
                    $due = $this->clock->combine($ymd, $time);
                    if ($due >= $this->now) {
                        continue; // future: produced by TaskGenerator
                    }
                    $minutesAgo = ($this->now->getTimestamp() - $due->getTimestamp()) / 60;

                    // today: the last ~1.5 h stay open (overdue on the portal); older history: ~3 % never recorded
                    $roll = mt_rand(1, 100);
                    if ($offset === 0 && $minutesAgo < 90 && $roll <= 70) {
                        $status = DailyTask::STATUS_OPEN;
                    } elseif ($roll <= 3) {
                        $status = DailyTask::STATUS_OPEN;
                    } elseif ($roll <= 8 && $template->Category !== TaskTemplate::CATEGORY_VITALS) {
                        $status = DailyTask::STATUS_SKIPPED;
                    } else {
                        $status = DailyTask::STATUS_DONE;
                    }

                    $task = DailyTask::create();
                    $task->PatientID = (int) $patient->ID;
                    $task->TemplateID = (int) $template->ID;
                    $task->DueDate = $ymd;
                    $task->DueTime = $time;
                    foreach ($template->snapshot() as $field => $value) {
                        $task->setField($field, $value);
                    }
                    $task->Status = $status;

                    if ($status !== DailyTask::STATUS_OPEN) {
                        $done = $due->modify('+' . mt_rand(2, 28) . ' minutes');
                        if ($done > $this->now) {
                            $done = $this->now->modify('-1 minute');
                        }
                        $task->CompletedAt = $this->clock->toDbDatetime($done);
                        $task->CompletedByID = (int) $this->workerAt($resident['zone'], $done)->ID;
                        if ($status === DailyTask::STATUS_SKIPPED) {
                            $task->SkipReason = $skipReasons[array_rand($skipReasons)];
                        } elseif (mt_rand(1, 100) <= 6) {
                            $task->ExecutionNote = 'Bez poteškoća, štićenik saradljiv.';
                        }
                    }
                    $task->write();
                    $tasks++;

                    if ($status === DailyTask::STATUS_DONE) {
                        foreach ($task->getVitalParameterIdList() as $definitionId) {
                            $definition = $this->vitalById($definitionId);
                            if (!$definition) {
                                continue;
                            }
                            $value = $this->vitalValue($definition, $resident, $offset);
                            $reading = VitalReading::create();
                            $reading->TaskID = (int) $task->ID;
                            $reading->PatientID = (int) $patient->ID;
                            $reading->DefinitionID = (int) $definition->ID;
                            $reading->DefinitionTitle = (string) $definition->Title;
                            $reading->Unit = (string) $definition->Unit;
                            $reading->Value = $value;
                            $reading->MeasuredAt = $task->CompletedAt;
                            $reading->IsAbnormal = $definition->isAbnormal($value);
                            $reading->write();
                            $readings++;
                        }
                    }
                }
            }
        }
        $this->log[] = sprintf('Historija: %d evidentiranih zadataka, %d izmjerenih vrijednosti.', $tasks, $readings);
        $future = DailyTask::get()->filter([
            'PatientID' => array_map(fn (array $r): int => (int) $r['patient']->ID, $this->residents),
            'DueDate:GreaterThan' => $this->today->format('Y-m-d'),
        ])->count();
        $this->log[] = sprintf('Budući zadaci (generator): %d.', $future);
    }

    private function vitalValue(VitalParameterDefinition $definition, array $resident, int $offset): float
    {
        $code = (string) $definition->Code;
        $base = $resident['base'][$code] ?? (((float) $definition->NormalMin + (float) $definition->NormalMax) / 2 ?: 50.0);
        $spread = match ($code) {
            'BP_SYS' => 12.0, 'BP_DIA' => 7.0, 'PULSE' => 7.0, 'TEMP' => 0.25,
            'SPO2' => 1.5, 'GLUCOSE' => $resident['diabetic'] ? 1.8 : 0.5, 'WEIGHT' => 0.4,
            default => 3.0,
        };
        $value = $base + (mt_rand(-1000, 1000) / 1000) * $spread;

        // a short fever episode 6–4 days ago for one resident (shows in report and history)
        if ($code === 'TEMP' && $resident['patient']->LastName === 'Kovačević' && $offset >= 4 && $offset <= 6) {
            $value = 37.6 + mt_rand(0, 10) / 10;
        }
        if ($code === 'SPO2') {
            $value = min(100.0, $value);
        }
        if ($definition->hasPlausibilityRange()) {
            $value = max((float) $definition->MinValue, min((float) $definition->MaxValue, $value));
        }

        return $definition->ValueType === VitalParameterDefinition::TYPE_INTEGER ? round($value) : round($value, 1);
    }

    private function vitalById(int $id): ?VitalParameterDefinition
    {
        foreach ($this->vitals as $definition) {
            if ((int) $definition->ID === $id) {
                return $definition;
            }
        }

        return null;
    }

    /* ========================================================= notes & meals */

    private function seedNotes(int $historyDays): void
    {
        $info = [
            'Posjeta porodice, raspoložen/a.',
            'Dobar apetit, pojeo/la cijeli obrok.',
            'Šetnja u bašti uz pratnju, 20 minuta.',
            'Učestvovao/la u radionici pamćenja.',
            'Mirno spavao/la cijelu noć.',
            'Promijenjena posteljina na zahtjev.',
        ];
        $important = [
            'Slabiji apetit, pojeo/la pola obroka. Pratiti.',
            'Blago otečeni gležnjevi, noge podignute.',
            'Žali se na bol u koljenu, dat analgetik po šemi.',
            'Nemiran/na u večernjim satima, traži porodicu.',
            'Odbija tečnost, ponuđen čaj svakih sat vremena.',
            'Crvenilo na sakralnom predjelu, pojačana promjena položaja.',
        ];
        $critical = [
            'Pad pri odlasku u toalet, bez vidljivih povreda. Dežurni ljekar obaviješten, kontrola ujutro.',
            'Šećer u krvi 3,1 mmol/L, dat sok i keks. Kontrola nakon 15 min: 5,4 mmol/L. Ljekar obaviješten.',
            'Temperatura 38,4 °C, ljekar pregledao, uveden antipiretik. Kontrola svaka 2 h.',
            'Otežano disanje, saturacija 89 %. Pozvana hitna pomoć, stanje stabilizovano na licu mjesta.',
            'Zbunjenost i dezorijentacija veći dio smjene. Porodica obaviještena.',
        ];

        $count = 0;
        for ($offset = $historyDays; $offset >= 0; $offset--) {
            $day = $this->today->modify('-' . $offset . ' days');
            $perDay = mt_rand(3, 6);
            for ($n = 0; $n < $perDay; $n++) {
                $resident = $this->residents[array_rand($this->residents)];
                $ymd = $day->format('Y-m-d');
                if ($ymd < $resident['from'] || $ymd > $resident['until']) {
                    continue;
                }
                $at = $this->clock->combine($ymd, sprintf('%02d:%02d:00', mt_rand(0, 23), mt_rand(0, 59)));
                if ($at > $this->now) {
                    continue;
                }
                $roll = mt_rand(1, 100);
                [$severity, $pool] = match (true) {
                    $roll <= 12 => [PatientNote::SEVERITY_CRITICAL, $critical],
                    $roll <= 45 => [PatientNote::SEVERITY_IMPORTANT, $important],
                    default => [PatientNote::SEVERITY_INFO, $info],
                };
                $note = PatientNote::create();
                $note->PatientID = (int) $resident['patient']->ID;
                $note->ZoneID = (int) $this->zones[$resident['zone']]->ID;
                $note->CreatedByID = (int) $this->workerAt($resident['zone'], $at)->ID;
                $note->Severity = $severity;
                $note->NoteText = $pool[array_rand($pool)];
                $note->Created = $this->clock->toDbDatetime($at);
                $note->write();
                $count++;
            }
        }
        $this->log[] = sprintf('Napomene: %d.', $count);
    }

    private function seedMeals(int $historyDays): void
    {
        $service = MealSelectionService::singleton();
        $count = 0;
        for ($offset = -$historyDays; $offset <= 1; $offset++) {
            $date = $this->today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
            $ymd = $date->format('Y-m-d');
            foreach ($this->residents as $resident) {
                if ($ymd < $resident['from'] || $ymd > $resident['until']) {
                    continue;
                }
                // tomorrow: about a quarter still undecided (shows "bez izbora" in the kitchen)
                if ($offset === 1 && mt_rand(1, 100) <= 25) {
                    continue;
                }
                foreach (array_keys(WeeklyMenuItem::mealTypeOptions()) as $mealType) {
                    $options = $service->optionsFor($resident['patient'], $date, $mealType)->toArray();
                    if (!$options) {
                        continue;
                    }
                    $item = $options[mt_rand(0, count($options) - 1)];
                    $selection = PatientMealSelection::create();
                    $selection->PatientID = (int) $resident['patient']->ID;
                    $selection->MenuItemID = (int) $item->ID;
                    $selection->RecordedByID = (int) $this->workerAt(
                        $resident['zone'],
                        $this->clock->combine($date->modify('-1 day')->format('Y-m-d'), '16:30:00')
                    )->ID;
                    $selection->write();
                    $count++;
                }
            }
        }
        $this->log[] = sprintf('Izbori obroka: %d.', $count);
    }

    private function seedHandovers(int $days): void
    {
        $shifts = ShiftResolver::singleton();
        $general = [
            'Sve uredno, bez posebnih događaja.',
            'Zalihe pelena u sobi 104 pri kraju, naručeno.',
            'Lift u zgradi A povremeno staje – prijavljeno održavanju.',
            'Porodica Begić dolazi sutra u 16 h na razgovor.',
            '',
            'Dostavljeni lijekovi iz apoteke, raspoređeni po sobama.',
        ];
        $count = 0;
        for ($offset = $days; $offset >= 0; $offset--) {
            $date = $this->today->modify('-' . $offset . ' days');
            $codes = array_keys($shifts->options());
            foreach ($codes as $position => $code) {
                $window = $shifts->window($date, $code);
                if (!$window || $window[1] > $this->now) {
                    continue; // shift not finished yet
                }
                foreach ($this->zones as $zoneKey => $zone) {
                    $log = ShiftHandoverLog::create();
                    $log->ZoneID = (int) $zone->ID;
                    $log->ShiftDate = $date->format('Y-m-d');
                    $log->ShiftType = $code;
                    $log->HandedOverByID = (int) $this->workerAt($zoneKey, $window[0])->ID;
                    $log->ReceivedByID = (int) $this->workerAt($zoneKey, $window[1])->ID;
                    $log->GeneralNotes = $general[array_rand($general)] ?: null;
                    $log->Created = $this->clock->toDbDatetime($window[1]->modify('-' . mt_rand(5, 20) . ' minutes'));
                    $log->write();
                    DemoSeedRecord::register($log);
                    $count++;
                }
            }
        }
        $this->log[] = sprintf('Primopredaje smjena: %d.', $count);
    }

    private function applyFinalStatuses(): void
    {
        // temporarily absent: in hospital since 2 days ago
        $absent = $this->residents[3]['patient'];
        $absent->IsTemporarilyAbsent = true;
        $absent->AbsenceNote = 'U bolnici (KCUS, pulmologija) od ' . $this->today->modify('-2 days')->format('d.m.') . '.';
        $absent->write();

        // discharged 5 days ago: bed becomes free, history stays
        $discharged = $this->residents[14]['patient'];
        $discharged->Status = Patient::STATUS_DISCHARGED;
        $discharged->ExitDate = $this->today->modify('-5 days')->format('Y-m-d');
        $discharged->ExitReason = 'Završena rehabilitacija nakon operacije kuka, otpust kući uz porodičnu njegu.';
        $discharged->write();
    }

    /* ================================================================ helpers */

    /**
     * Care worker on duty in a zone at a moment (rotation by shift).
     */
    private function workerAt(string $zoneKey, DateTimeImmutable $at): Member
    {
        $shifts = ShiftResolver::singleton();
        $current = $shifts->current($at);
        $codes = array_keys($shifts->options());
        $position = $current ? (int) array_search($current['code'], $codes, true) : 0;

        $rotation = $zoneKey === 'B0' ? ['lejla', 'nermin', 'senad'] : ['amra', 'nermin', 'senad'];

        return $this->workers[$rotation[$position % 3]];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createRoot(string $class, array $data): DataObject
    {
        /** @var DataObject $record */
        $record = $class::create($data);
        $record->write();
        DemoSeedRecord::register($record);

        return $record;
    }

    /**
     * Valid ex-YU JMBG: DDMMYYY RR BBB K (region 17 = Sarajevo).
     */
    private static function jmbg(string $dob, bool $female, int $index): string
    {
        [$y, $m, $d] = explode('-', $dob);
        $serial = ($female ? 500 : 0) + 100 + $index * 7;
        $base = $d . $m . substr($y, 1) . '17' . sprintf('%03d', $serial);
        $digits = array_map('intval', str_split($base));
        $sum = 7 * ($digits[0] + $digits[6]) + 6 * ($digits[1] + $digits[7]) + 5 * ($digits[2] + $digits[8])
            + 4 * ($digits[3] + $digits[9]) + 3 * ($digits[4] + $digits[10]) + 2 * ($digits[5] + $digits[11]);
        $control = 11 - ($sum % 11);

        // control 10 is invalid by definition: pick the next serial
        if ($control === 10) {
            return self::jmbg($dob, $female, $index + 1);
        }

        return $base . ($control === 11 ? 0 : $control);
    }
}
