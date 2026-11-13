<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wehrkalender\App;

#[AsCommand(name: 'sources:fetch', description: 'Externe iCal-Feeds abrufen, deren Intervall abgelaufen ist (oder eine Quelle sofort)')]
final class SourcesFetchCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('quelle', null, InputOption::VALUE_REQUIRED, 'nur diese Quelle (Schlüssel), unabhängig vom Intervall');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'ohne ETag abrufen und trotz Plausibilitätsproblem übernehmen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fetcher = $this->app->sourceFetcher();
        $key = $input->getOption('quelle');
        if ($key !== null) {
            $source = $this->app->db()->one("SELECT * FROM sources WHERE source_key = ? AND type = 'ical'", [$key]);
            if ($source === null) {
                $output->writeln("<error>Keine iCal-Quelle mit dem Schlüssel „{$key}“.</error>");
                return Command::FAILURE;
            }
            $sources = [$source];
        } else {
            $sources = $fetcher->due();
        }
        $failed = 0;
        foreach ($sources as $s) {
            $r = $fetcher->fetch($s, (bool) $input->getOption('force'));
            $failed += in_array($r['status'], ['ok', 'unchanged'], true) ? 0 : 1;
            $output->writeln(sprintf('%-20s %-9s %s', $s['source_key'], $r['status'], $r['message']));
        }
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
