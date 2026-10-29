<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Scout\ModelObserver;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Support\PermissionName;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Enums\TicketPriority;
use Modules\SAO\Models\ClosureAudit;
use Modules\SAO\Models\ClosurePolicy;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SourceProfile;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Models\TicketType;
use Modules\SAO\Models\WorkflowScheme;
use Modules\SAO\Models\WorkflowTransition;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dev fixture for the SAO SPA: a demo project with a real workflow (statuses +
 * transitions) and tickets spread across it (so the ticket kanban and its
 * guided-drop transitions have content), plus connections, signals, ownership
 * suggestions, releases, environments and closure policies for the other list
 * views and the dashboard. Also seeds a `sao-agent` role and user so the
 * module-scoped login can be exercised. Idempotent.
 */
final class DevSAODatabaseSeeder extends Seeder
{
    private const DEMO_PROJECT = 'SAO Demo';

    /**
     * Entities the agent may read (CRUD select) in the SPA.
     *
     * @var list<class-string<Model>>
     */
    private const READABLE = [
        Ticket::class,
        TicketStatus::class,
        Project::class,
        TicketType::class,
        Signal::class,
        Connection::class,
        OwnershipSuggestion::class,
        Release::class,
        Environment::class,
        ClosurePolicy::class,
        ClosureAudit::class,
        IngestEvent::class,
        SourceProfile::class,
    ];

    public function run(): void
    {
        // Suppress synchronous search indexing while bulk-creating searchable
        // tickets: a dev seed must not hammer the search engine per row. Indexing
        // is left to an explicit reindex (scout:reindex) once the engine is ready.
        ModelObserver::disableSyncingFor(Ticket::class);

        try {
            Model::unguarded(function (): void {
                $this->seedRoleAndUser();
                $this->seedProjectData();
            });
        } finally {
            ModelObserver::enableSyncingFor(Ticket::class);
        }
    }

    private function seedProjectData(): void
    {
        if (Project::query()->withoutGlobalScopes()->where('name', self::DEMO_PROJECT)->exists()) {
            $this->command?->line('    - SAO demo project already present');

            return;
        }

        $this->command?->line('    - seeding SAO demo project, workflow and tickets');

        $todo = TicketStatus::factory()->category(StatusCategory::Open)->create(['name' => 'To Do', 'order_column' => 1]);
        $doing = TicketStatus::factory()->category(StatusCategory::InProgress)->create(['name' => 'In Progress', 'order_column' => 2]);
        $blocked = TicketStatus::factory()->category(StatusCategory::Open)->create(['name' => 'Blocked', 'order_column' => 3]);
        $done = TicketStatus::factory()->category(StatusCategory::Closed)->create(['name' => 'Done', 'order_column' => 4]);

        $scheme = WorkflowScheme::factory()->create(['name' => 'SAO Demo Workflow']);
        $edges = [
            [null, $todo, 'Open'],
            [$todo, $doing, 'Start work'],
            [$todo, $blocked, 'Block'],
            [$blocked, $doing, 'Unblock'],
            [$doing, $todo, 'Send back'],
            [$doing, $done, 'Resolve'],
        ];

        foreach ($edges as [$from, $to, $label]) {
            WorkflowTransition::factory()->for($scheme, 'scheme')->create([
                'from_status_id' => $from?->id,
                'to_status_id' => $to->id,
                'label' => $label,
            ]);
        }

        $type = TicketType::factory()->create(['name' => 'Task', 'slug' => 'task', 'workflow_scheme_id' => $scheme->id]);
        $project = Project::factory()->create(['name' => self::DEMO_PROJECT, 'key_prefix' => 'SAO']);
        $project->ticketTypes()->attach($type->id, ['is_default' => true]);

        $statuses = ['todo' => $todo, 'doing' => $doing, 'blocked' => $blocked, 'done' => $done];

        /**
         * @var array<int, array{0: string, 1: TicketPriority, 2: string}> $tickets
         */
        $tickets = [
            ['todo', TicketPriority::High, 'Login non funziona su Safari'],
            ['todo', TicketPriority::Normal, 'Aggiungere export CSV allo storico'],
            ['todo', TicketPriority::Low, 'Migliorare accessibilità dei form'],
            ['doing', TicketPriority::Urgent, 'Timeout intermittente API pagamenti'],
            ['doing', TicketPriority::Normal, 'Refactor modulo notifiche'],
            ['blocked', TicketPriority::High, 'In attesa credenziali fornitore'],
            ['done', TicketPriority::Normal, 'Fix typo nella homepage'],
            ['done', TicketPriority::Low, 'Aggiornare dipendenze minori'],
        ];
        $createdTickets = [];

        foreach ($tickets as [$statusKey, $priority, $title]) {
            $createdTickets[] = Ticket::factory()->forProject($project)->create([
                'ticket_type_id' => $type->id,
                'ticket_status_id' => $statuses[$statusKey]->id,
                'priority' => $priority,
                'title' => $title,
            ]);
        }

        Connection::factory()->create(['name' => 'GitHub — acme', 'driver_key' => 'github', 'capabilities' => [Capability::Issues]]);
        Connection::factory()->create(['name' => 'Graylog — prod', 'driver_key' => 'graylog', 'capabilities' => [Capability::Logs]]);
        Signal::factory()->count(5)->create(['project_id' => $project->id]);

        // Ownership suggestions point at real demo tickets (the factory would
        // otherwise spin up throwaway tickets of its own).
        foreach (array_slice($createdTickets, 0, 3) as $ticket) {
            OwnershipSuggestion::factory()->create(['ticket_id' => $ticket->id]);
        }
        Release::factory()->for($project)->count(2)->create();
        Environment::factory()->for($project)->create(['name' => 'production']);
        Environment::factory()->for($project)->create(['name' => 'staging']);
        ClosurePolicy::factory()->for($project)->count(2)->create();
    }

    private function seedRoleAndUser(): void
    {
        $permissions = [
            PermissionName::forClass(Ticket::class, 'transition'),
            PermissionName::forClass(Ticket::class, 'transitions'),
            PermissionName::forClass(Ticket::class, 'close'),
            PermissionName::forClass(OwnershipSuggestion::class, 'accept'),
            PermissionName::forClass(Connection::class, 'health'),
            PermissionName::forClass(IngestEvent::class, 'replay'),
            PermissionName::forClass(IngestEvent::class, 'reprocess'),
        ];

        foreach (self::READABLE as $model) {
            $permissions[] = PermissionName::forClass($model, 'select');
        }

        // Bypass Spatie Permission::findOrCreate — it hydrates from
        // `spatie.permission.cache`, which can be a bare Collection (no "alias"
        // key) after parallel seeding / cache failover. Match MES/ERP seeders:
        // firstOrCreate on the query builder, with explicit cache flushes.
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permission = new Permission;

        foreach (array_unique($permissions) as $name) {
            $permission->newQuery()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $registrar->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate([
            'name' => 'sao-agent',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permissions);

        $user = User::query()->where('email', 'sao.agent@laraplate.test')->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => 'SAO Agent',
                'email' => 'sao.agent@laraplate.test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_first_login' => false,
            ]);
        }

        if (! $user->hasRole($role->name)) {
            $user->assignRole($role->name);
        }

        $this->command?->line('    - SAO role/user (sao-agent) <fg=green>ready</> (password: password)');
    }
}
