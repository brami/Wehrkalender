<?php

declare(strict_types=1);

namespace Kalender;

use Kalender\Calendar\EventStore;
use Kalender\Calendar\IcsBuilder;
use Kalender\Database\Db;
use Kalender\Database\Migrator;
use Kalender\Domain\AccountService;
use Kalender\Domain\MembershipService;
use Kalender\Domain\StatusService;
use Kalender\Domain\UsernamePolicy;
use Kalender\Mail\MailQueue;
use Kalender\Mail\MailSender;
use Kalender\Security\LoginThrottle;
use Kalender\Security\PasswordService;
use Kalender\Web\TwigExtension;
use Slim\Views\Twig;

/**
 * Einfacher Dienst-Container: erzeugt alle Dienste bei Bedarf genau einmal.
 */
final class App
{
    /** @var array<string,object> */
    private array $services = [];

    public function __construct(public readonly Config $config, private ?Db $db = null, private ?Clock $clock = null)
    {
    }

    public static function boot(string $baseDir, ?string $envFile = null): self
    {
        $config = Config::load($baseDir, $envFile);
        date_default_timezone_set('UTC');
        return new self($config);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable():T $factory
     * @return T
     */
    private function get(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config);
    }

    public function clock(): Clock
    {
        return $this->clock ??= new Clock();
    }

    public function migrator(): Migrator
    {
        return $this->get(Migrator::class, fn () => new Migrator($this->db(), $this->config->baseDir() . '/migrations', $this->clock()));
    }

    public function passwords(): PasswordService
    {
        return $this->get(PasswordService::class, fn () => new PasswordService($this->config->get('APP_KEY')));
    }

    public function usernames(): UsernamePolicy
    {
        return $this->get(UsernamePolicy::class, fn () => new UsernamePolicy($this->db()));
    }

    public function throttle(): LoginThrottle
    {
        return $this->get(LoginThrottle::class, fn () => new LoginThrottle($this->db(), $this->clock(), $this->config->get('APP_KEY', 'kalender')));
    }

    public function view(): Twig
    {
        return $this->get(Twig::class, function () {
            $twig = Twig::create($this->config->baseDir() . '/templates', [
                'cache' => $this->config->env() === 'prod' ? $this->config->storagePath('cache/twig') : false,
                'strict_variables' => $this->config->env() !== 'prod',
            ]);
            $twig->addExtension(new TwigExtension($this->config));
            // Vorbelegung; die Middleware überschreibt die Werte je Anfrage
            foreach (['current_user' => null, 'csrf' => '', 'flashes' => [], 'last_import' => null,
                      'last_attempt' => null, 'current_path' => ''] as $k => $v) {
                $twig->getEnvironment()->addGlobal($k, $v);
            }
            return $twig;
        });
    }

    public function ics(): IcsBuilder
    {
        return $this->get(IcsBuilder::class, fn () => new IcsBuilder());
    }

    public function events(): EventStore
    {
        return $this->get(EventStore::class, fn () => new EventStore($this->db(), $this->ics(), $this->clock(), $this->config));
    }

    public function mailQueue(): MailQueue
    {
        return $this->get(MailQueue::class, fn () => new MailQueue($this->db(), $this->view()->getEnvironment(), $this->clock()));
    }

    public function mailSender(): MailSender
    {
        return $this->get(MailSender::class, fn () => new MailSender($this->db(), $this->config, $this->clock()));
    }

    public function accounts(): AccountService
    {
        return $this->get(AccountService::class, fn () => new AccountService(
            $this->db(),
            $this->clock(),
            $this->config,
            $this->passwords(),
            $this->usernames(),
            $this->mailQueue(),
        ));
    }

    public function memberships(): MembershipService
    {
        return $this->get(MembershipService::class, fn () => new MembershipService($this->db(), $this->clock(), $this->config, $this->mailQueue(), $this->accounts()));
    }

    public function status(): StatusService
    {
        return $this->get(StatusService::class, fn () => new StatusService($this->db(), $this->clock(), $this->config, $this->mailQueue()));
    }
}
