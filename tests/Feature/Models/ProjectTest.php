<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Models\Project;

uses(RefreshDatabase::class)->group('db2');

test('a project is created with an uppercase key prefix', function (): void {
    $project = Project::factory()->create([
        'name' => 'Simply Another Orchestrator',
        'key_prefix' => 'SAO',
    ]);

    expect($project->key_prefix)->toBe('SAO');
    expect($project->is_active)->toBeTrue();
});

/**
 * The application runs with strict mass assignment, so filling a guarded
 * attribute raises rather than being silently discarded. Only
 * TicketKeyAllocator may move this counter, and only under a row lock.
 */
test('the ticket counter starts at zero and refuses mass assignment', function (): void {
    $project = Project::factory()->create();

    expect($project->next_ticket_number)->toBe(0);

    expect(fn (): Project => $project->fill(['next_ticket_number' => 99]))
        ->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);

    expect($project->fresh()->next_ticket_number)->toBe(0);
});

/**
 * Core models validate on save, so the `unique:` rule rejects the duplicate
 * before the database constraint ever sees it. The unique index remains as the
 * last line of defence for writes that bypass the model.
 */
test('two projects cannot share a key prefix', function (): void {
    Project::factory()->create(['key_prefix' => 'SAO']);

    expect(fn (): Project => Project::factory()->create(['key_prefix' => 'SAO']))
        ->toThrow(Modules\Core\Overrides\ContextualValidationException::class);
});

test('the create rules require a name and a prefix of two to ten uppercase characters', function (): void {
    $rules = (new Project)->getRules()['create'];

    expect($rules['name'])->toContain('required');
    expect($rules['key_prefix'])->toContain('required');
    expect($rules['key_prefix'])->toContain('regex:/^[A-Z][A-Z0-9]{1,9}$/');
});
