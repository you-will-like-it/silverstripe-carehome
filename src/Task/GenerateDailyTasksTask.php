<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use YouWillLikeIT\CareHome\Model\Patient\Patient;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\TaskGenerator;

/**
 * Daily cron, e.g.:
 *   5 0 * * *  www-data  cd /var/www/carehome && vendor/bin/sake tasks:carehome-generate-tasks
 *
 * Optional: --patient=ID to sync a single resident.
 */
class GenerateDailyTasksTask extends BuildTask
{
    protected static string $commandName = 'carehome-generate-tasks';

    protected string $title = 'CareHome: generiši dnevne zadatke';

    protected static string $description = 'Sinhronizuje DailyTask zapise sa šablonima za danas + horizon_days (podrazumijevano 7).';

    /** Browser execution only for full admins; cron runs via CLI. */
    private static array $permissions_for_browser_execution = ['ADMIN'];

    public function getOptions(): array
    {
        return [
            new InputOption('patient', null, InputOption::VALUE_REQUIRED, 'Sync only this resident ID'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        AuditContext::singleton()->setChannel(AuditContext::CHANNEL_CLI);
        $generator = TaskGenerator::singleton();

        try {
            $patientId = (int) $input->getOption('patient');
            if ($patientId > 0) {
                $patient = Patient::get()->byID($patientId);
                if (!$patient) {
                    $output->writeln("<error>Resident #{$patientId} not found.</error>");

                    return Command::FAILURE;
                }
                $stats = $generator->syncPatient($patient);
                $output->writeln(sprintf(
                    'Resident #%d: %d created, %d updated, %d deleted.',
                    $patientId,
                    $stats['created'],
                    $stats['updated'],
                    $stats['deleted']
                ));

                return Command::SUCCESS;
            }

            $totals = $generator->syncAll();
            $output->writeln(sprintf(
                '%d residents synced: %d created, %d updated, %d deleted.',
                $totals['patients'],
                $totals['created'],
                $totals['updated'],
                $totals['deleted']
            ));
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
