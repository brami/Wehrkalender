<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Wehrkalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'monitor:check', description: 'Warnung an die Administratoren bei ausbleibendem oder fehlerhaftem Import')]
final class MonitorCheckCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Warnung auch senden, wenn heute schon gewarnt wurde');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->app->status()->monitor((bool) $input->getOption('force'));
        if ($r['problems'] === []) {
            $output->writeln('Alles in Ordnung.');
            return Command::SUCCESS;
        }
        foreach ($r['problems'] as $p) {
            $output->writeln('- ' . $p);
        }
        $output->writeln($r['sent'] > 0 ? "Warnung an {$r['sent']} Empfänger eingereiht." : 'Heute wurde bereits gewarnt.');
        return Command::FAILURE;
    }
}
