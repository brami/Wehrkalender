<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Wehrkalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'mail:test', description: 'Test-E-Mail direkt über MAILER_DSN senden (auch bei MAIL_DELIVERY=database)')]
final class MailTestCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('empfaenger', InputArgument::REQUIRED, 'E-Mail-Adresse für die Test-E-Mail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cfg = $this->app->config;
        $sender = $this->app->mailSender();
        $to = (string) $input->getArgument('empfaenger');
        $output->writeln('MAIL_DELIVERY:  ' . $sender->deliveryMode());
        $output->writeln('MAILER_DSN:     ' . ($sender->maskedDsn() ?: '(leer)'));
        $output->writeln('MAIL_FROM:      ' . $cfg->get('MAIL_FROM', 'kalender@' . $cfg->domain()));
        $output->writeln('Empfänger:      ' . $to);
        try {
            $sender->sendTest($to);
        } catch (Throwable $e) {
            $output->writeln('<error>Versand fehlgeschlagen: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Test-E-Mail wurde vom SMTP-Server angenommen.</info>');
        if ($sender->deliveryMode() !== 'smtp') {
            $output->writeln('Hinweis: MAIL_DELIVERY=database – die übrigen E-Mails werden weiterhin nicht verschickt.');
        }
        return Command::SUCCESS;
    }
}
