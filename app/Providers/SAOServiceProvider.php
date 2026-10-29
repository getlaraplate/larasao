<?php

declare(strict_types=1);

namespace Modules\SAO\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Exceptions\ConfigurationException;
use Modules\Core\Import\Support\EntityImporterRegistry;
use Modules\Core\Logging\Fingerprint\Fingerprinter;
use Modules\Core\Logging\Fingerprint\FingerprintNormalizer;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\SAO\ApplicationContent\SaoApplicationContentRetrievalProvider;
use Modules\SAO\Contracts\SuggestionPhraser;
use Modules\SAO\Contracts\SuggestionTextGenerator;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Import\TicketImporter;
use Modules\SAO\Ingest\PipelineContext;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Policies\SaoModelPolicy;
use Modules\SAO\Services\AiSuggestionPhraser;
use Modules\SAO\Services\DomainActions\SaoDomainActionRegistrar;
use Modules\SAO\Services\EventTextGenerator;
use Modules\SAO\Services\TicketOwnerAuthorizer;
use Nwidart\Modules\Facades\Module;
use Override;

final class SAOServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'SAO';

    #[Override]
    protected string $nameLower = 'sao';

    /**
     * Provider classes to register.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    #[Override]
    public function register(): void
    {
        throw_unless(Module::find('Core'), ConfigurationException::class, 'Core is required and must be enabled');

        parent::register();

        $this->app->singleton(DriverRegistry::class, static function ($app): DriverRegistry {
            $registry = new DriverRegistry;

            /** @var list<class-string> $registered */
            $registered = (array) config('sao.drivers.registered', []);

            foreach ($registered as $driver) {
                $registry->register($app->make($driver));
            }

            return $registry;
        });

        // The shared fingerprinter is built from Core's default rule chain so
        // the payload path and the in-process resolver hash identically.
        $this->app->bind(
            Fingerprinter::class,
            static fn (): Fingerprinter => new Fingerprinter(FingerprintNormalizer::default()),
        );

        // The pipeline-origin marker must be shared process-wide.
        $this->app->singleton(PipelineContext::class);

        // Optional AI phrasing without an AI dependency: the phraser asks for a
        // rewrite through Core's AiTextGenerationRequested event and falls back
        // to its deterministic text when nothing answers. It never invents the
        // owner (D14).
        $this->app->bind(SuggestionTextGenerator::class, EventTextGenerator::class);
        $this->app->bind(SuggestionPhraser::class, AiSuggestionPhraser::class);
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        $this->app
            ->make(ApplicationContentRetrievalProviderRegistryInterface::class)
            ->register($this->app->make(SaoApplicationContentRetrievalProvider::class));

        $this->app
            ->make(OwnerAuthorizerRegistry::class)
            ->register($this->app->make(TicketOwnerAuthorizer::class));

        foreach ($this->policyModels() as $model) {
            Gate::policy($model, SaoModelPolicy::class);
        }

        resolve(SaoDomainActionRegistrar::class)->register(resolve(DomainActionRegistry::class));

        resolve(EntityImporterRegistry::class)->register(resolve(TicketImporter::class));
    }

    /**
     * Schedule the inbound issue poll, the signal auto-open, the connection
     * health probe and the census probe. Each is gated by its own config flag so
     * an operator can keep it manual-only, and each is harmless when on with
     * nothing configured (a binding must opt into an inbound sync direction to be
     * polled; a signal must reach the threshold to open a ticket; the health
     * probe simply finds no connections; the census probe finds no probe
     * binding).
     */
    #[Override]
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            if (config('sao.sync.enabled', true)) {
                $schedule->command('sao:sync:issues')
                    ->cron((string) config('sao.sync.cron', '0 * * * *'))
                    ->withoutOverlapping();
            }

            if (config('sao.signals.auto_open.enabled', false)) {
                $schedule->command('sao:signals:auto-open')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            }

            if (config('sao.health.enabled', false)) {
                $schedule->command('sao:connection:health')
                    ->cron((string) config('sao.health.cron', '*/15 * * * *'))
                    ->withoutOverlapping();
            }

            if (config('sao.probe.enabled', false)) {
                $schedule->command('sao:census:probe')
                    ->cron((string) config('sao.probe.cron', '0 */6 * * *'))
                    ->withoutOverlapping();
            }

            if (config('sao.retention.enabled', false)) {
                $schedule->command('sao:prune')
                    ->cron((string) config('sao.retention.cron', '0 3 * * *'))
                    ->withoutOverlapping();
            }
        });
    }

    /**
     * SAO models that expose domain actions through {@see SaoModelPolicy}.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function policyModels(): array
    {
        return [
            Ticket::class,
            OwnershipSuggestion::class,
            Connection::class,
            IngestEvent::class,
        ];
    }
}
