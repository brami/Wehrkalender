<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Wehrkalender\App;
use Wehrkalender\TestData\TestDataLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'testdata:load', description: 'Datenbank LÖSCHEN und mit Testdaten für alle Anwendungsfälle füllen')]
final class TestDataCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('import', null, InputOption::VALUE_REQUIRED, 'Zustand des letzten Imports: ok | failed | stale', 'ok')
            ->addOption('passwort', null, InputOption::VALUE_REQUIRED, 'Gleiches Passwort für alle Testbenutzer (sonst zufällig)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Ohne Rückfrage')
            ->setHelp(<<<'TXT'
Löscht ALLE Tabellen der konfigurierten Datenbank und legt Testdaten an:
Kalender aus der Projektbeschreibung, rund 30 Mitglieder für jeden Fall
(gesperrt, veralteter Benutzername, Umlaute/Doppelnamen, nie angemeldet,
Begrüßung abgelaufen, Admin, Geschwister mit gleicher E-Mail …), Termine
(Serien, ganztägig, mehrtägig, abgesagt, Zeitumstellung, verschoben),
Importprotokolle (erfolgreich, fehlgeschlagen, abgebrochen) und E-Mails
(versendet, offen, fehlgeschlagen).

Die Zugangsdaten werden ausgegeben und in storage/testdaten-zugaenge.txt
gespeichert.

  --import=failed   letzter Import fehlgeschlagen (Fußzeile, Status-Seite)
  --import=stale    letzter Export vor 30 h (monitor:check warnt, /health = 503)
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->app->config->get('DB_DRIVER', 'mysql') === 'sqlite'
            ? 'SQLite ' . $this->app->config->get('DB_PATH')
            : 'MariaDB ' . $this->app->config->get('DB_NAME', 'wehrkalender') . '@' . $this->app->config->get('DB_HOST', 'db');
        if ($this->app->config->env() === 'prod' && !$input->getOption('yes')) {
            $output->writeln('<error>APP_ENV=prod: Testdaten würden die Produktivdaten löschen.</error>');
            $output->writeln('Nur mit --yes, wenn das wirklich gewollt ist (z. B. auf dem Testsystem mit kalender_test).');
            return Command::FAILURE;
        }
        if (!$input->getOption('yes') && $input->isInteractive()) {
            $q = new ConfirmationQuestion("Alle Daten in {$db} werden gelöscht. Fortfahren? [j/N] ", false, '/^(j|y)/i');
            if (!$this->getHelper('question')->ask($input, $output, $q)) {
                return Command::FAILURE;
            }
        }
        $import = (string) $input->getOption('import');
        if (!in_array($import, ['ok', 'failed', 'stale'], true)) {
            $output->writeln('<error>--import muss ok, failed oder stale sein.</error>');
            return Command::INVALID;
        }

        $loader = new TestDataLoader($this->app);
        $result = $loader->load(['import' => $import, 'password' => $input->getOption('passwort')],
            fn (string $s) => $output->writeln("<comment>{$s}</comment>"));

        $buffer = new BufferedOutput();
        foreach ([$output, $buffer] as $out) {
            $out->writeln('');
            $out->writeln('Testdaten in ' . $db . ' – Server ' . $this->app->config->baseUrl());
            $out->writeln('');
            $table = new Table($out);
            $table->setHeaders(['Benutzername', 'Passwort', 'Status', 'Testfall']);
            foreach ($result['logins'] as $l) {
                $table->addRow([$l['username'], $l['password'], $l['status'], $l['case']]);
            }
            $table->render();
            $out->writeln('');
            $out->writeln('Links zum Testen:');
            foreach ($result['links'] as $label => $url) {
                $out->writeln("  {$label}\n    {$url}");
            }
            $out->writeln('');
            $out->writeln('Umfang: ' . implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($result['counts']), $result['counts'])));
        }
        $file = $this->app->config->storagePath('testdaten-zugaenge.txt');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0770, true);
        }
        file_put_contents($file, $buffer->fetch());
        chmod($file, 0600);
        $output->writeln("Gespeichert in {$file}");
        return Command::SUCCESS;
    }
}
