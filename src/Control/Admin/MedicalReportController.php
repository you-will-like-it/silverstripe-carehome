<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Control\Admin;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;
use YouWillLikeIT\CareHome\Service\AuditLogger;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\MedicalReportService;

/**
 * Medical PDF report (CMS users with CAREHOME_REPORTS only).
 *   /carehome-report/form/{patientID}                 date range picker
 *   /carehome-report/pdf/{patientID}?from=Y-m-d&to=Y-m-d
 */
class MedicalReportController extends Controller
{
    private static string $url_segment = 'carehome-report';

    private static array $allowed_actions = ['form', 'pdf'];

    private static array $url_handlers = [
        'form/$ID!' => 'form',
        'pdf/$ID!' => 'pdf',
    ];

    public static function formLink(int $patientId): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), 'form', $patientId);
    }

    public static function pdfLinkForDays(int $patientId, int $days): string
    {
        $clock = Clock::singleton();
        $to = $clock->today();
        $from = $to->modify('-' . max(0, $days - 1) . ' days');

        return Controller::join_links(
            Director::baseURL(),
            static::config()->get('url_segment'),
            'pdf',
            $patientId,
            '?from=' . $from->format('Y-m-d') . '&to=' . $to->format('Y-m-d')
        );
    }

    protected function init(): void
    {
        parent::init();
        HTTPCacheControlMiddleware::singleton()->disableCache(true);

        $member = Security::getCurrentUser();
        if (!$member || !CareHomePermissions::check(CareHomePermissions::REPORTS, $member)) {
            Security::permissionFailure($this);
        }
    }

    public function form(HTTPRequest $request): DBHTMLText
    {
        $patient = $this->patientOr404($request);
        $today = Clock::singleton()->today();

        return $this->customise([
            'Title' => _t(__CLASS__ . '.TITLE', 'Ljekarski izvještaj'),
            'Patient' => $patient,
            'DefaultFrom' => $today->modify('-13 days')->format('Y-m-d'),
            'DefaultTo' => $today->format('Y-m-d'),
            'Action' => Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), 'pdf', $patient->ID),
            'MaxDays' => MedicalReportService::singleton()->maxDays(),
        ])->renderWith('YouWillLikeIT\\CareHome\\Admin\\MedicalReportForm');
    }

    public function pdf(HTTPRequest $request): HTTPResponse
    {
        $patient = $this->patientOr404($request);
        $clock = Clock::singleton();
        $service = MedicalReportService::singleton();

        $from = $clock->parseDate((string) $request->getVar('from'));
        $to = $clock->parseDate((string) $request->getVar('to'));
        if (!$from || !$to || $from > $to) {
            $this->httpError(400, _t(__CLASS__ . '.INVALID_RANGE', 'Neispravan datumski raspon.'));
        }
        if ($from->diff($to)->days >= $service->maxDays()) {
            $this->httpError(400, _t(__CLASS__ . '.RANGE_TOO_LONG', 'Raspon je predug.'));
        }

        $pdf = $service->renderPdf($patient, $from, $to);

        AuditLogger::singleton()->logEvent(
            AuditLogEntry::ACTION_EVENT,
            _t(__CLASS__ . '.AUDIT', 'Ljekarski izvještaj generisan: {name}', ['name' => $patient->getFullName()]),
            null,
            ['patientID' => (int) $patient->ID, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );

        $filename = sprintf(
            'izvjestaj-%s-%s-%s.pdf',
            preg_replace('/[^a-z0-9]+/i', '-', DBField::create_field('Varchar', $patient->LastName)->LimitCharacters(40, '')) ?: 'pacijent',
            $from->format('Ymd'),
            $to->format('Ymd')
        );

        $response = HTTPResponse::create($pdf, 200);
        $response->addHeader('Content-Type', 'application/pdf');
        $response->addHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
        $response->addHeader('Cache-Control', 'private, no-store');

        return $response;
    }

    private function patientOr404(HTTPRequest $request): Patient
    {
        /** @var Patient|null $patient */
        $patient = Patient::get()->byID((int) $request->param('ID'));
        if (!$patient) {
            $this->httpError(404);
        }

        return $patient;
    }
}
