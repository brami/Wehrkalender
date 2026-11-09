<?php

declare(strict_types=1);

namespace Kalender\Console;

use Kalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:send', description: 'Mail-Warteschlange abarbeiten (höchstens 60 E-Mails)')]
final class MailSendCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->app->db();
        if (!$db->lock('kalender-mail', 0)) {
            $output->writeln('Mail-Job läuft bereits.', OutputInterface::VERBOSITY_VERBOSE);
            return Command::SUCCESS;
        }
        try {
            $r = $this->app->mailSender()->run();
        } finally {
            $db->unlock('kalender-mail');
        }
        if (array_sum($r) > 0 || $output->isVerbose()) {
            $output->writeln(sprintf('Modus %s: %d versendet, %d fehlgeschlagen, %d nicht versendet (Testbetrieb).',
                $this->app->mailSender()->deliveryMode(), $r['sent'], $r['failed'], $r['suppressed']));
        }
        return $r['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
