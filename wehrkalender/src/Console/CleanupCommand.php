<?php

declare(strict_types=1);

namespace Wehrkalender\Console;

use Wehrkalender\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cleanup:run', description: 'Abgelaufene Einmal-Links, alte Protokolle, E-Mails und Archivdateien löschen')]
final class CleanupCommand extends Command
{
    public function __construct(private App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->app->db();
        $clock = $this->app->clock();
        $n = [];
        $n['Einmal-Links'] = $db->execute('DELETE FROM login_tokens WHERE expires_at < ?', [$clock->db('-1 day')]);
        $n['Anmeldeversuche'] = $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [$clock->db('-1 day')]);
        $n['Importprotokolle (90 Tage)'] = $db->execute('DELETE FROM import_runs WHERE started_at < ?', [$clock->db('-90 days')]);
        // E-Mails enthalten Anmelde- und Abo-Links → nicht unbegrenzt aufheben
        $n['E-Mails (30 Tage)'] = $db->execute(
            "DELETE FROM mail_queue WHERE status IN ('sent', 'suppressed') AND created_at < ?", [$clock->db('-30 days')]);
        $n['fehlgeschlagene E-Mails (90 Tage)'] = $db->execute(
            "DELETE FROM mail_queue WHERE status = 'failed' AND created_at < ?", [$clock->db('-90 days')]);
        // calendar_changes wird bewusst NICHT gekürzt: Ein Client mit älterem Sync-Token
        // bekäme sonst unvollständige Änderungslisten (die Tabelle bleibt klein).

        $archive = $this->app->config->get('IMPORT_ARCHIVE', 'storage/archive');
        if (!str_starts_with($archive, '/')) {
            $archive = $this->app->config->baseDir() . '/' . $archive;
        }
        $files = 0;
        if (is_dir($archive)) {
            $limit = time() - 30 * 86400;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($archive, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getMTime() < $limit && unlink($file->getPathname())) {
                    $files++;
                }
            }
        }
        $n['Archivdateien (30 Tage)'] = $files;
        foreach ($n as $what => $count) {
            $output->writeln(sprintf('%-36s %d gelöscht', $what, $count));
        }
        return Command::SUCCESS;
    }
}
