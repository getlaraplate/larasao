<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\EngineManager;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Tests\Support\FakeVectorProfile;
use Modules\SAO\Models\Ticket;

uses(RefreshDatabase::class);

it('declares title and description as the embeddable fields', function (): void {
    expect((new Ticket())->getEmbedFields())->toBe(['title', 'description']);
});

it('emits a single agnostic embeddings entry in the search document', function (): void {
    // Create the ticket first under the suite's default Scout driver — swapping the driver
    // beforehand would route the factory's own save-triggered indexing through the fake
    // engine below, which stubs only what this test needs (supportsVectorSearch).
    $ticket = Ticket::factory()->create([
        'title' => 'Deploy failed on staging',
        'description' => 'The pipeline went red after the last release.',
    ]);

    // Saving an embeddable ticket may have run whatever embedding pipeline the application
    // has installed (SAO does not depend on one). Replace anything it produced with one
    // deterministic row so this test asserts the document shape, not that pipeline's output.
    $ticket->embeddings()->delete();
    $ticket->embeddings()->create([
        'embedding' => [0.1, 0.2, 0.3],
        'locale' => null,
        'model_key' => 'test-embedding-model',
    ]);

    // The suite's default 'collection' Scout driver does not implement ISearchEngine, so
    // toSearchableArray() would never reach the embeddings branch. Register a fake engine
    // under a throwaway driver name instead, mirroring
    // SaoApplicationContentRetrievalProviderTest's `sao-application-content-test` pattern.
    FakeVectorProfile::use(new VectorProfile('test-embedding-model', 3, 'cosine'));
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturnTrue();
    app(EngineManager::class)->extend('ticket-embeddable-test', static fn () => $engine);
    Config::set('scout.driver', 'ticket-embeddable-test');

    $document = $ticket->fresh()->toSearchableArray();

    expect($document['embeddings'])->toBe([['vector' => [0.1, 0.2, 0.3]]]);
});

it('declares embeddings as a nested vector field of the vector profile in the search mapping, unconditionally', function (): void {
    FakeVectorProfile::use(new VectorProfile('test-embedding-model', 1024, 'dot_product'));
    // Force the Elasticsearch translator so the mapping shape can be asserted without
    // depending on which Scout driver the environment happens to have configured,
    // mirroring ContentSearchableArrayTest's mapping assertion.
    Config::set('scout.driver', 'elasticsearch');

    // A brand-new, unsaved Ticket has no ModelEmbedding rows, so toSearchableArray()
    // would omit the `embeddings` key entirely — the mapping must still declare the
    // field, since an index is normally created before any ticket has been embedded.
    $mapping = (new Ticket())->getSearchMapping();
    $properties = $mapping['mappings']['properties'];

    expect($properties['embeddings']['type'])->toBe('nested')
        ->and($properties['embeddings']['properties']['vector']['type'])->toBe('dense_vector')
        ->and($properties['embeddings']['properties']['vector']['dims'])->toBe(1024)
        ->and($properties['embeddings']['properties']['vector']['similarity'])->toBe('dot_product')
        ->and($properties['title']['type'])->toBe('text');
});

it('declares the embeddings field without a nested vector when there is no vector profile', function (): void {
    FakeVectorProfile::none();
    Config::set('scout.driver', 'elasticsearch');

    $properties = (new Ticket())->getSearchMapping()['mappings']['properties'];

    expect($properties['embeddings'])->not->toHaveKey('properties');
});
