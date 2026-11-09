<?php

declare(strict_types=1);

namespace Kalender\Console;

use Kalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:admin', description: 'Administratorrechte vergeben (oder mit --revoke entziehen)')]
final class UserAdminCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Benutzername, z. B. max.mustermann')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Rechte entziehen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = strtolower((string) $input->getArgument('username'));
        $revoke = (bool) $input->getOption('revoke');
        if (!$this->app->accounts()->setAdmin($username, !$revoke)) {
            $output->writeln("<error>Benutzer „{$username}“ nicht gefunden.</error>");
            return Command::FAILURE;
        }
        $output->writeln($revoke ? "Administratorrechte für {$username} entzogen." : "{$username} ist jetzt Administrator.");
        return Command::SUCCESS;
    }
}
