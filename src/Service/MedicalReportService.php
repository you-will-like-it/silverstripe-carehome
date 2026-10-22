<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Service;

use DateTimeImmutable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\SiteConfig\SiteConfig;
use YouWillLikeIT\CareHome\Model\Note\PatientNote;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Model\Task\DailyTask;
use YouWillLikeIT\CareHome\Model\Task\TaskTemplate;
use YouWillLikeIT\CareHome\Model\Task\VitalParameterDefinition;
use YouWillLikeIT\CareHome\Model\Task\VitalReading;
use YouWillLikeIT\CareHome\Pdf\PdfRendererInterface;

/**
 * Builds the medical report data and renders it to PDF.
 */
class MedicalReportService
{
    use Injectable;
    use Configurable;

    /** Name/address printed in the report header. Falls back to SiteConfig title if available. */
    private static string $facility_name = '';

    private static string $facility_address = '';

    /** Maximum report span in days. */
    private static int $max_days = 366;

    public function __construct(
        private readonly Clock $clock,
        private readonly PdfRendererInterface $renderer,
    ) {
    }

    public function maxDays(): int
    {
        return (int) static::config()->get('max_days');
    }

    public function renderPdf(Patient $patient, DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $data = $this->build($patient, $from, $to);

        $body = (string) $data->renderWith('YouWillLikeIT/CareHome/Pdf/MedicalReport');
        $header = (string) $data->renderWith('YouWillLikeIT/CareHome/Pdf/MedicalReportHeader');
        $footer = (string) $data->renderWith('YouWillLikeIT/CareHome/Pdf/MedicalReportFooter');

        return $this->renderer->render($body, $header, $footer, (string) $data->getField('DocumentTitle'));
    }

    public function build(Patient $patient, DateTimeImmutable $from, DateTimeImmutable $to): ArrayData
    {
        $fromYmd = $from->format('Y-m-d');
        $toYmd = $to->format('Y-m-d');
        $now = $this->clock->now();

        return ArrayData::create([
            'DocumentTitle' => _t(__CLASS__ . '.TITLE', 'Ljekarski izvještaj') . ' – ' . $patient->getFullName(),
            'FacilityName' => $this->facilityName(),
            'FacilityAddress' => (string) static::config()->get('facility_address'),
            'Patient' => $patient,
            'From' => DBField::create_field('Date', $fromYmd),
            'To' => DBField::create_field('Date', $toYmd),
            'GeneratedAt' => DBField::create_field('Datetime', $this->clock->toDbDatetime($now)),
            'Vitals' => $this->vitals($patient, $fromYmd, $toYmd),
            'Therapy' => $this->therapy($patient, $fromYmd, $toYmd, $now),
            'Notes' => PatientNote::get()->filter([
                'PatientID' => (int) $patient->ID,
                'Created:GreaterThanOrEqual' => $fromYmd . ' 00:00:00',
                'Created:LessThanOrEqual' => $toYmd . ' 23:59:59',
            ])->sort('Created', 'ASC'),
        ]);
    }

    /**
     * Matrix: one row per measurement (task), one column per parameter.
     */
    private function vitals(Patient $patient, string $fromYmd, string $toYmd): ArrayData
    {
        $readings = VitalReading::get()->filter([
            'PatientID' => (int) $patient->ID,
            'MeasuredAt:GreaterThanOrEqual' => $fromYmd . ' 00:00:00',
            'MeasuredAt:LessThanOrEqual' => $toYmd . ' 23:59:59',
        ])->sort('MeasuredAt', 'ASC');

        $definitionIds = array_unique(array_map('intval', $readings->column('DefinitionID')));
        $definitions = $definitionIds
            ? VitalParameterDefinition::get()->filter('ID', $definitionIds)->sort('SortOrder')
            : VitalParameterDefinition::get()->filter('ID', 0);

        $rows = [];
        foreach ($readings as $reading) {
            $key = (int) $reading->TaskID . '|' . $reading->MeasuredAt;
            $rows[$key] ??= ['MeasuredAt' => $reading->MeasuredAt, 'Values' => []];
            $rows[$key]['Values'][(int) $reading->DefinitionID] = $reading;
        }

        $outRows = ArrayList::create();
        foreach ($rows as $row) {
            $cells = ArrayList::create();
            foreach ($definitions as $definition) {
                $reading = $row['Values'][(int) $definition->ID] ?? null;
                $cells->push(ArrayData::create([
                    'Value' => $reading ? $definition->formatValue((float) $reading->Value) : '',
                    'IsAbnormal' => $reading ? (bool) $reading->IsAbnormal : false,
                ]));
            }
            $outRows->push(ArrayData::create([
                'MeasuredAt' => DBField::create_field('Datetime', $row['MeasuredAt']),
                'Cells' => $cells,
            ]));
        }

        return ArrayData::create([
            'Columns' => $definitions,
            'Rows' => $outRows,
            'HasRows' => $outRows->count() > 0,
        ]);
    }

    private function therapy(Patient $patient, string $fromYmd, string $toYmd, DateTimeImmutable $now): ArrayData
    {
        $tasks = DailyTask::get()->filter([
            'PatientID' => (int) $patient->ID,
            'Category' => TaskTemplate::CATEGORY_THERAPY,
            'DueDate:GreaterThanOrEqual' => $fromYmd,
            'DueDate:LessThanOrEqual' => $toYmd,
        ])->sort(['DueDate' => 'ASC', 'DueTime' => 'ASC']);

        $rows = ArrayList::create();
        $done = $skipped = $missed = 0;
        foreach ($tasks as $task) {
            if ($task->isOpen() && $task->getDueAt() >= $now) {
                continue; // not due yet
            }
            $state = match (true) {
                $task->isDone() => 'done',
                $task->isSkipped() => 'skipped',
                default => 'missed',
            };
            match ($state) {
                'done' => $done++,
                'skipped' => $skipped++,
                default => $missed++,
            };
            $rows->push(ArrayData::create([
                'Task' => $task,
                'State' => $state,
                'StateLabel' => match ($state) {
                    'done' => _t(__CLASS__ . '.STATE_DONE', 'Dato'),
                    'skipped' => _t(__CLASS__ . '.STATE_SKIPPED', 'Preskočeno'),
                    default => _t(__CLASS__ . '.STATE_MISSED', 'Nije evidentirano'),
                },
            ]));
        }

        return ArrayData::create([
            'Rows' => $rows,
            'HasRows' => $rows->count() > 0,
            'Done' => $done,
            'Skipped' => $skipped,
            'Missed' => $missed,
        ]);
    }

    private function facilityName(): string
    {
        $name = (string) static::config()->get('facility_name');
        if ($name === '' && class_exists(SiteConfig::class)) {
            $name = (string) SiteConfig::current_site_config()->Title;
        }

        return $name;
    }
}
