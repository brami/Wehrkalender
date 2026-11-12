<?php

declare(strict_types=1);

namespace Kalender\Console;

use Kalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Platzhalter: Der Import aus MP Feuer (Quellen, Mapper, Verarbeitung) ist
 * laut Auftrag noch nicht Teil dieser Lieferung, weil Exportformat und
 * Übertragungsweg mit MP Feuer noch abgestimmt werden. Der Befehl existiert,
 * damit Zeitpläne (supercronic/systemd) schon eingerichtet werden können.
 *
 * Bausteine für die spätere Umsetzung:
 *   AccountService::createUser(), ::needsWelcome(), ::queueWelcome()
 *   MembershipService::replaceMemberships(), ::recompute(notify: …)
 *   EventStore::save(), ::delete()
 *   StatusService::setState('import.last_export_seen_at', …), ::alertAdmins()
 *   Import\EmailValidator::isValid() (wirft Import\DnsUnavailable bei DNS-Ausfall)
 *   Db::lock('kalender-import') / Db::transaction()
 */
#[AsCommand(name: 'import:run', description: 'Import aus MP Feuer (noch nicht umgesetzt)')]
final class ImportRunCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Plausibilitätsprüfung übergehen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Import aus MP Feuer ist noch nicht umgesetzt (wartet auf Abstimmung des Exportformats).',
            OutputInterface::VERBOSITY_VERBOSE);
        return Command::SUCCESS;
    }
}
