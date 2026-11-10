<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Control\Portal;

use RuntimeException;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Throwable;
use YouWillLikeIT\CareHome\Model\Facility\FacilityBed;
use YouWillLikeIT\CareHome\Model\Kitchen\WeeklyMenuItem;
use YouWillLikeIT\CareHome\Model\Note\PatientNote;
use YouWillLikeIT\CareHome\Model\Note\ShiftHandoverLog;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\TaskTemplate;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Security\PortalAuthenticator;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\MealSelectionService;
use YouWillLikeIT\CareHome\Service\ShiftResolver;
use YouWillLikeIT\CareHome\Service\TaskStatusService;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Worker portal: PIN login, zone overview, QR scan → resident → today's checklist.
 * Plain server-rendered forms with Post/Redirect/Get — works on any phone, survives flaky Wi-Fi.
 */
class PortalController extends AbstractPortalController
{
    private static string $url_segment = 'portal';

    private static array $allowed_actions = [
        'index',
        'login',
        'logout',
        'bed',
        'patient',
        'photo',
        'task',
        'note',
        'meal',
        'handover',
    ];

    private static array $url_handlers = [
        'bed/$Hash!' => 'bed',
        'patient/$ID!/note' => 'note',
        'patient/$ID!/meal' => 'meal',
        'patient/$ID!' => 'patient',
        'photo/$ID!' => 'photo',
        'task/$ID!/$Op!' => 'task',
        'handover' => 'handover',
        'login' => 'login',
        'logout' => 'logout',
    ];

    /** Days of history shown on the resident page. */
    private static int $history_days = 7;

    protected function permissionCode(): string
    {
        return CareHomePermissions::PORTAL;
    }

    protected function auditChannel(): string
    {
        return AuditContext::CHANNEL_PORTAL;
    }

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), $action);
    }

    public static function patientLink(int $patientId): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), 'patient', $patientId);
    }

    /* ---------------------------------------------------------------- auth */

    public function index(HTTPRequest $request): DBHTMLText
    {
        $worker = $this->currentWorker();
        if (!$worker) {
            return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Login', [
                'Title' => _t(__CLASS__ . '.LOGIN_TITLE', 'Prijava'),
                'LoginAction' => $this->Link('login'),
            ]);
        }

        return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Home', [
            'Title' => _t(__CLASS__ . '.HOME_TITLE', 'Moji štićenici'),
            'Zones' => ZoneScope::singleton()->assignedZones($worker),
            'Residents' => $this->residentOverview($worker),
            'CurrentShift' => $this->currentShiftLabel(),
        ]);
    }

    public function login(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());

        $result = PortalAuthenticator::singleton()->attempt(
            $request,
            (string) $request->postVar('pin'),
            [$this->permissionCode(), CareHomePermissions::KITCHEN]
        );
        if ($result['error']) {
            $this->flash('error', $result['error']);
        }

        // kitchen-only PIN entered here: continue in the kitchen view
        return $this->redirect($result['area'] === CareHomePermissions::KITCHEN
            ? KitchenController::singleton()->Link()
            : $this->Link());
    }

    public function logout(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());
        PortalAuthenticator::singleton()->logout($request, $this->currentWorker(), $this->permissionCode());

        return $this->redirect($this->Link());
    }

    /* --------------------------------------------------------- residents */

    public function bed(HTTPRequest $request): HTTPResponse|DBHTMLText
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }

        $hash = (string) $request->param('Hash');
        /** @var FacilityBed|null $bed */
        $bed = preg_match('/^[a-f0-9]{16,64}$/', $hash)
            ? FacilityBed::get()->filter('UniqueHash', $hash)->first()
            : null;
        if (!$bed) {
            return $this->notice(404, _t(__CLASS__ . '.BED_UNKNOWN', 'Nepoznat QR kod.'));
        }
        if (!ZoneScope::singleton()->canAccessBed($worker, $bed)) {
            return $this->notice(403, _t(__CLASS__ . '.BED_FORBIDDEN', 'Ovaj krevet nije u vašoj zoni.'));
        }

        $patient = $bed->CurrentPatient();
        if (!$patient) {
            return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\EmptyBed', [
                'Title' => $bed->getFullTitle(),
                'Bed' => $bed,
            ]);
        }

        return $this->redirect(self::patientLink((int) $patient->ID));
    }

    public function patient(HTTPRequest $request): HTTPResponse|DBHTMLText
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $patient = $this->accessiblePatient($worker, (int) $request->param('ID'));
        if (!$patient) {
            return $this->notice(404, _t(__CLASS__ . '.PATIENT_UNKNOWN', 'Štićenik nije pronađen ili nije u vašoj zoni.'));
        }

        if ($patient->IsTemporarilyAbsent) {
            return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\AbsentPatient', [
                'Title' => $patient->getFullName(),
                'Patient' => $patient,
            ]);
        }

        $clock = Clock::singleton();
        $today = $clock->today();
        $meals = MealSelectionService::singleton();
        $mealDate = $meals->targetDate();

        return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Patient', [
            'Title' => $patient->getFullName(),
            'Patient' => $patient,
            'BedTitle' => $patient->getBedTitle(),
            'Initials' => mb_strtoupper(mb_substr((string) $patient->FirstName, 0, 1) . mb_substr((string) $patient->LastName, 0, 1)),
            'PhotoLink' => $patient->hasPhoto() ? $this->Link('photo/' . $patient->ID) : null,
            'Today' => DBField::create_field('Date', $today->format('Y-m-d')),
            'OverdueTasks' => $this->taskRows($worker, $patient, $today->modify('-1 day')->format('Y-m-d'), true),
            'TodayTasks' => $this->taskRows($worker, $patient, $today->format('Y-m-d'), false),
            'History' => $this->history($patient),
            'RecentNotes' => PatientNote::get()
                ->filter([
                    'PatientID' => (int) $patient->ID,
                    'Created:GreaterThanOrEqual' => $today->modify('-3 days')->format('Y-m-d 00:00:00'),
                ])
                ->sort('Created', 'DESC')
                ->limit(15),
            'SeverityOptions' => $this->options(PatientNote::severityOptions(), PatientNote::SEVERITY_INFO),
            'MealDate' => DBField::create_field('Date', $mealDate->format('Y-m-d')),
            'MealOpen' => $meals->isSelectionOpen(),
            'MealCutoff' => $meals->getCutoff(),
            'Meals' => $this->mealRows($patient),
            'NoteAction' => $this->Link('patient/' . $patient->ID . '/note'),
            'MealAction' => $this->Link('patient/' . $patient->ID . '/meal'),
        ]);
    }

    public function photo(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $patient = $this->accessiblePatient($worker, (int) $request->param('ID'));
        if (!$patient || !$patient->hasPhoto()) {
            $this->httpError(404);
        }

        /** @var Image $image */
        $image = $patient->Photo();
        $variant = null;
        try {
            $variant = $image->Fill(320, 320);
        } catch (Throwable) {
            // fall back to the original file
        }
        $file = $variant ?: $image;

        $response = HTTPResponse::create((string) $file->getString(), 200);
        $response->addHeader('Content-Type', (string) ($file->getMimeType() ?: 'image/jpeg'));
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /* ------------------------------------------------------------- actions */

    public function task(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }

        /** @var DailyTask|null $task */
        $task = DailyTask::get()->byID((int) $request->param('ID'));
        if (!$task) {
            $this->httpError(404);
        }
        $back = self::patientLink((int) $task->PatientID) . '#task-' . $task->ID;
        $this->assertPostWithToken($request, $back);

        $service = TaskStatusService::singleton();
        try {
            match ((string) $request->param('Op')) {
                'complete' => $service->complete(
                    $task,
                    $worker,
                    (string) $request->postVar('note'),
                    (array) ($request->postVar('vitals') ?? [])
                ),
                'skip' => $service->skip($task, $worker, (string) $request->postVar('reason')),
                'undo' => $service->undo($task, $worker),
                default => $this->httpError(400),
            };
            $this->flash('success', _t(__CLASS__ . '.TASK_SAVED', 'Sačuvano: {title}', ['title' => $task->Title]));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (ValidationException) {
            $this->flash('error', _t(__CLASS__ . '.TASK_CONFLICT', 'Zadatak je u međuvremenu evidentirao drugi radnik.'));
        }

        return $this->redirect($back);
    }

    public function note(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $patient = $this->accessiblePatient($worker, (int) $request->param('ID'));
        if (!$patient) {
            $this->httpError(404);
        }
        $back = self::patientLink((int) $patient->ID) . '#notes';
        $this->assertPostWithToken($request, $back);

        $text = trim((string) $request->postVar('text'));
        $severity = (string) $request->postVar('severity');
        if ($text === '' || !array_key_exists($severity, PatientNote::severityOptions())) {
            $this->flash('error', _t(__CLASS__ . '.NOTE_INVALID', 'Unesite tekst napomene i odaberite važnost.'));

            return $this->redirect($back);
        }

        $note = PatientNote::create();
        $note->PatientID = (int) $patient->ID;
        $note->CreatedByID = (int) $worker->ID;
        $note->NoteText = mb_substr($text, 0, 4000);
        $note->Severity = $severity;
        $note->write();

        $this->flash('success', _t(__CLASS__ . '.NOTE_SAVED', 'Napomena sačuvana.'));

        return $this->redirect($back);
    }

    public function meal(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $patient = $this->accessiblePatient($worker, (int) $request->param('ID'));
        if (!$patient) {
            $this->httpError(404);
        }
        $back = self::patientLink((int) $patient->ID) . '#meals';
        $this->assertPostWithToken($request, $back);

        $service = MealSelectionService::singleton();
        $saved = 0;
        try {
            foreach (array_keys(WeeklyMenuItem::mealTypeOptions()) as $mealType) {
                $itemId = (int) $request->postVar('meal_' . $mealType);
                if ($itemId > 0) {
                    $service->select($patient, $mealType, $itemId, $worker);
                    $saved++;
                }
            }
            $this->flash(
                $saved ? 'success' : 'error',
                $saved
                    ? _t(__CLASS__ . '.MEAL_SAVED', 'Izbor obroka za sutra je sačuvan.')
                    : _t(__CLASS__ . '.MEAL_NONE', 'Niste odabrali nijedan meni.')
            );
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($back);
    }

    /* ------------------------------------------------------------ handover */

    public function handover(HTTPRequest $request): HTTPResponse|DBHTMLText
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }

        $shifts = ShiftResolver::singleton();
        $current = $shifts->current();
        $zones = ZoneScope::singleton()->assignedZones($worker);

        if ($request->isPOST()) {
            return $this->saveHandover($request, $worker, $current);
        }

        $zoneBlocks = ArrayList::create();
        foreach ($zones as $zone) {
            $preview = ShiftHandoverLog::create();
            $preview->ZoneID = (int) $zone->ID;
            if ($current) {
                $preview->ShiftDate = $current['date']->format('Y-m-d');
                $preview->ShiftType = $current['code'];
            }
            $zoneBlocks->push(ArrayData::create([
                'Zone' => $zone,
                'Breadcrumb' => $zone->getBreadcrumb(),
                'CriticalNotes' => $current ? $preview->CriticalNotes() : ArrayList::create(),
            ]));
        }

        $zoneIds = array_map('intval', $zones->column('ID'));
        $zoneOptions = [];
        foreach ($zones as $zone) {
            $zoneOptions[(int) $zone->ID] = $zone->getBreadcrumb();
        }

        return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Handover', [
            'Title' => _t(__CLASS__ . '.HANDOVER_TITLE', 'Primopredaja smjene'),
            'CurrentShift' => $this->currentShiftLabel(),
            'HasShift' => $current !== null,
            'ZoneBlocks' => $zoneBlocks,
            'ZoneOptions' => $this->options($zoneOptions, null),
            'Receivers' => $this->receiverOptions($worker),
            'RecentLogs' => ShiftHandoverLog::get()->filter('ZoneID', $zoneIds ?: [0])->limit(10),
            'FormAction' => $this->Link('handover'),
        ]);
    }

    /**
     * @param array{code:string,date:\DateTimeImmutable}|null $current
     */
    private function saveHandover(HTTPRequest $request, Member $worker, ?array $current): HTTPResponse
    {
        $back = $this->Link('handover');
        $this->assertPostWithToken($request, $back);

        $zoneId = (int) $request->postVar('zone');
        $receiverId = (int) $request->postVar('receiver');
        $zoneAllowed = in_array($zoneId, array_map('intval', ZoneScope::singleton()->assignedZones($worker)->column('ID')), true);
        $receiver = $receiverId ? Member::get()->byID($receiverId) : null;

        if (!$current || !$zoneAllowed || ($receiverId && !$receiver)) {
            $this->flash('error', _t(__CLASS__ . '.HANDOVER_INVALID', 'Provjerite zonu i radnika koji preuzima smjenu.'));

            return $this->redirect($back);
        }

        $log = ShiftHandoverLog::create();
        $log->ZoneID = $zoneId;
        $log->ShiftDate = $current['date']->format('Y-m-d');
        $log->ShiftType = $current['code'];
        $log->GeneralNotes = mb_substr(trim((string) $request->postVar('notes')), 0, 8000);
        $log->HandedOverByID = (int) $worker->ID;
        $log->ReceivedByID = $receiver ? (int) $receiver->ID : 0;
        $log->write();

        $this->flash('success', _t(__CLASS__ . '.HANDOVER_SAVED', 'Primopredaja je evidentirana.'));

        return $this->redirect($back);
    }

    /* ------------------------------------------------------------- helpers */

    private function accessiblePatient(Member $worker, int $id): ?Patient
    {
        /** @var Patient|null $patient */
        $patient = $id > 0 ? Patient::get()->byID($id) : null;

        return $patient && ZoneScope::singleton()->canAccessPatient($worker, $patient) ? $patient : null;
    }

    private function notice(int $status, string $message): DBHTMLText
    {
        $this->getResponse()->setStatusCode($status);

        return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Notice', [
            'Title' => $message,
            'Message' => $message,
        ]);
    }

    /**
     * Residents in scope with today's progress, sorted by room.
     */
    private function residentOverview(Member $worker): ArrayList
    {
        $patients = ZoneScope::singleton()->patientsFor($worker)->toArray();
        $ids = array_map(static fn (Patient $p): int => (int) $p->ID, $patients);

        $clock = Clock::singleton();
        $todayYmd = $clock->today()->format('Y-m-d');
        $nowTime = $clock->now()->format('H:i:s');

        $stats = [];
        if ($ids) {
            $tasks = SQLSelect::create(
                ['"PatientID"', '"Status"', '"DueTime"'],
                '"' . DataObject::getSchema()->tableName(DailyTask::class) . '"',
                ['"DueDate" = ?' => $todayYmd, '"PatientID" IN (' . DB::placeholders($ids) . ')' => $ids]
            )->execute();
            foreach ($tasks as $row) {
                $id = (int) $row['PatientID'];
                $stats[$id] ??= ['Total' => 0, 'Closed' => 0, 'Overdue' => 0];
                $stats[$id]['Total']++;
                if ((int) $row['Status'] !== DailyTask::STATUS_OPEN) {
                    $stats[$id]['Closed']++;
                } elseif ((string) $row['DueTime'] < $nowTime) {
                    $stats[$id]['Overdue']++;
                }
            }
        }

        $rows = [];
        foreach ($patients as $patient) {
            $bed = $patient->Bed();
            $room = $bed->Room();
            $s = $stats[(int) $patient->ID] ?? ['Total' => 0, 'Closed' => 0, 'Overdue' => 0];
            $rows[] = [
                'Patient' => $patient,
                'Link' => self::patientLink((int) $patient->ID),
                'RoomNumber' => (string) $room->RoomNumber,
                'BedLabel' => (string) $bed->BedLabel,
                'ZoneTitle' => $room->Zone()->getBreadcrumb(),
                'IsAbsent' => (bool) $patient->IsTemporarilyAbsent,
                'Total' => (int) $s['Total'],
                'Closed' => (int) $s['Closed'],
                'Overdue' => (int) $s['Overdue'],
                'Percent' => $s['Total'] ? (int) round(100 * $s['Closed'] / $s['Total']) : 0,
                'HasMedicalAlerts' => trim((string) $patient->MedicalAlerts) !== '',
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp(
            $a['ZoneTitle'] . '|' . $a['RoomNumber'] . '|' . $a['BedLabel'],
            $b['ZoneTitle'] . '|' . $b['RoomNumber'] . '|' . $b['BedLabel']
        ));

        return ArrayList::create(array_map(static fn (array $r): ArrayData => ArrayData::create($r), $rows));
    }

    /**
     * @param bool $onlyOpen used for yesterday's leftovers
     */
    private function taskRows(Member $worker, Patient $patient, string $ymd, bool $onlyOpen): ArrayList
    {
        $filter = ['PatientID' => (int) $patient->ID, 'DueDate' => $ymd];
        if ($onlyOpen) {
            $filter['Status'] = DailyTask::STATUS_OPEN;
        }
        $service = TaskStatusService::singleton();

        $rows = ArrayList::create();
        foreach (DailyTask::get()->filter($filter)->sort(['DueTime' => 'ASC', 'ID' => 'ASC']) as $task) {
            $rows->push(ArrayData::create([
                'Task' => $task,
                'IsOverdue' => $task->isOverdue(),
                'IsVitals' => $task->Category === TaskTemplate::CATEGORY_VITALS,
                'IsTherapy' => $task->Category === TaskTemplate::CATEGORY_THERAPY,
                'Definitions' => $task->VitalDefinitions()->sort('SortOrder'),
                'CanUndo' => $service->canUndo($task, $worker),
                'UndoMinutes' => $service->undoMinutesLeft($task, $worker),
                'CompleteAction' => $this->Link('task/' . $task->ID . '/complete'),
                'SkipAction' => $this->Link('task/' . $task->ID . '/skip'),
                'UndoAction' => $this->Link('task/' . $task->ID . '/undo'),
            ]));
        }

        return $rows;
    }

    /**
     * Executed vitals/therapy of the previous days, grouped by day (newest first).
     */
    private function history(Patient $patient): ArrayList
    {
        $clock = Clock::singleton();
        $days = (int) static::config()->get('history_days');
        $from = $clock->today()->modify('-' . $days . ' days')->format('Y-m-d');
        $to = $clock->today()->modify('-1 day')->format('Y-m-d');

        $tasks = DailyTask::get()->filter([
            'PatientID' => (int) $patient->ID,
            'Category' => [TaskTemplate::CATEGORY_VITALS, TaskTemplate::CATEGORY_THERAPY],
            'DueDate:GreaterThanOrEqual' => $from,
            'DueDate:LessThanOrEqual' => $to,
        ])->sort(['DueDate' => 'DESC', 'DueTime' => 'ASC']);

        $grouped = [];
        foreach ($tasks as $task) {
            $grouped[(string) $task->DueDate][] = $task;
        }

        $result = ArrayList::create();
        foreach ($grouped as $ymd => $list) {
            $result->push(ArrayData::create([
                'Date' => DBField::create_field('Date', $ymd),
                'Tasks' => ArrayList::create($list),
            ]));
        }

        return $result;
    }

    private function mealRows(Patient $patient): ArrayList
    {
        $service = MealSelectionService::singleton();
        $date = $service->targetDate();

        $rows = ArrayList::create();
        foreach (WeeklyMenuItem::mealTypeOptions() as $mealType => $label) {
            $selection = $service->currentSelection($patient, $date, $mealType);
            $selectedId = $selection ? (int) $selection->MenuItemID : 0;
            $options = ArrayList::create();
            foreach ($service->optionsFor($patient, $date, $mealType) as $item) {
                $options->push(ArrayData::create([
                    'ID' => (int) $item->ID,
                    'OptionName' => $item->OptionName,
                    'Description' => $item->Description,
                    'Selected' => (int) $item->ID === $selectedId,
                ]));
            }
            $rows->push(ArrayData::create([
                'MealType' => $mealType,
                'Label' => $label,
                'FieldName' => 'meal_' . $mealType,
                'Options' => $options,
                'HasSelection' => $selectedId > 0,
            ]));
        }

        return $rows;
    }

    private function receiverOptions(Member $worker): ArrayList
    {
        $portalIds = array_map('intval', Permission::get_members_by_permission(CareHomePermissions::PORTAL)->column('ID'));
        $members = Member::get()
            ->filter(['ID' => $portalIds ?: [0], 'PortalEnabled' => true])
            ->exclude('ID', (int) $worker->ID)
            ->sort(['FirstName' => 'ASC', 'Surname' => 'ASC']);
        // e.g. the roster add-on narrows this to the workers of the next shift
        $this->extend('updateHandoverReceivers', $members, $worker);

        $list = ArrayList::create();
        foreach ($members as $member) {
            $list->push(ArrayData::create(['Value' => (int) $member->ID, 'Label' => $member->getName()]));
        }

        return $list;
    }

    /**
     * @param array<int|string,string> $options
     */
    private function options(array $options, int|string|null $selected): ArrayList
    {
        $list = ArrayList::create();
        foreach ($options as $value => $label) {
            $list->push(ArrayData::create([
                'Value' => $value,
                'Label' => $label,
                'Selected' => $selected !== null && (string) $value === (string) $selected,
            ]));
        }

        return $list;
    }

    private function currentShiftLabel(): string
    {
        $current = ShiftResolver::singleton()->current();

        return $current ? (ShiftResolver::singleton()->options()[$current['code']] ?? $current['code']) : '';
    }
}
