<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Wehrkalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:migrate', description: 'Datenbank-Migrationen ausführen')]
final class MigrateCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->app->migrator()->migrate();
        $output->writeln($applied === [] ? 'Datenbank ist aktuell.' : 'Ausgeführt: ' . implode(', ', $applied));
        return Command::SUCCESS;
    }
}
