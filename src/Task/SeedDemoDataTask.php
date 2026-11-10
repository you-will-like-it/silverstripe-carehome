<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Task;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use YouWillLikeIT\CareHome\Dev\DemoSeeder;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\AuditLogger;

/**
 * Presentation data. Every run first removes the previous demo data, then seeds again
 * relative to today (refresh):
 *
 *   vendor/bin/sake tasks:carehome-seed-demo
 *   vendor/bin/sake tasks:carehome-seed-demo --future=30 --history=14
 *   vendor/bin/sake tasks:carehome-seed-demo --purge        # remove demo data only
 *
 * Refuses to run in "live" environment mode unless --force is given.
 */
class SeedDemoDataTask extends BuildTask
{
    protected static string $commandName = 'carehome-seed-demo';

    protected string $title = 'CareHome: demo podaci za prezentaciju';

    protected static string $description = 'Briše prethodne demo podatke i generiše nove (historija + naredni period). Dira samo demo zapise.';

    private static array $permissions_for_browser_execution = ['ADMIN'];

    public function getOptions(): array
    {
        return [
            new InputOption('future', null, InputOption::VALUE_REQUIRED, 'Days ahead with tasks and menus', '30'),
            new InputOption('history', null, InputOption::VALUE_REQUIRED, 'Days of history (tasks, vitals, notes, meals)', '14'),
            new InputOption('purge', null, InputOption::VALUE_NONE, 'Only remove demo data'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Allow running in live mode'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (Director::isLive() && !$input->getOption('force')) {
            $output->writeln('<error>Okruženje je "live". Demo podaci se ne generišu bez --force.</error>');

            return Command::FAILURE;
        }

        $future = max(1, min(90, (int) $input->getOption('future')));
        $history = max(1, min(60, (int) $input->getOption('history')));
        AuditContext::singleton()->setChannel(AuditContext::CHANNEL_CLI);

        try {
            $seeder = DemoSeeder::singleton();
            $purgeOnly = (bool) $input->getOption('purge');
            $lines = $purgeOnly ? $seeder->purge() : $seeder->seed($future, $history);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln($e->getFile() . ':' . $e->getLine());

            return Command::FAILURE;
        }

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        AuditLogger::singleton()->logEvent(
            AuditLogEntry::ACTION_EVENT,
            $purgeOnly ? 'Demo podaci uklonjeni' : 'Demo podaci generisani',
            null,
            $purgeOnly ? [] : ['future' => $future, 'history' => $history]
        );

        $output->writeln($purgeOnly ? 'Gotovo.' : 'Gotovo. Portal: /portal (PIN iznad), kuhinja: /kuhinja.');

        return Command::SUCCESS;
    }
}
