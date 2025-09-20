<?php

namespace Vizra\VizraADK\Services\Drivers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\VectorMemoryDriverInterface;
use Vizra\VizraADK\Models\VectorMemory;

class InMemoryVectorDriver implements VectorMemoryDriverInterface
{
    public function store(VectorMemory $memory): bool
    {
        // For in-memory driver, we don't need to do additional storage
        // The memory is already stored in the database by VectorMemoryManager
        Log::debug('InMemory driver: Memory stored in database', [
            'memory_id' => $memory->id,
            'agent_name' => $memory->agent_name,
            'namespace' => $memory->namespace,
        ]);

        return true;
    }

    public function search(
        string $agentName,
        array $queryEmbedding,
        string $namespace = 'default',
        int $limit = 5,
        float $threshold = 0.7
    ): Collection {
        Log::debug('InMemory driver: Performing cosine similarity search', [
            'agent_name' => $agentName,
            'namespace' => $namespace,
            'query_dimensions' => count($queryEmbedding),
            'limit' => $limit,
            'threshold' => $threshold,
        ]);

        // Get all memories for this agent and namespace
        $memories = VectorMemory::forAgent($agentName)
            ->inNamespace($namespace)
            ->get();

        // Calculate cosine similarity for each memory
        $results = $memories->map(function (VectorMemory $memory) use ($queryEmbedding) {
            $similarity = $memory->cosineSimilarity($queryEmbedding);

            return (object) [
                'id' => $memory->id,
                'agent_name' => $memory->agent_name,
                'namespace' => $memory->namespace,
                'content' => $memory->content,
                'metadata' => $memory->metadata,
                'source' => $memory->source,
                'source_id' => $memory->source_id,
                'embedding_provider' => $memory->embedding_provider,
                'embedding_model' => $memory->embedding_model,
                'created_at' => $memory->created_at,
                'similarity' => $similarity,
            ];
        })
            ->filter(fn ($result) => $result->similarity >= $threshold)
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();

        Log::debug('InMemory driver: Search completed', [
            'agent_name' => $agentName,
            'namespace' => $namespace,
            'total_memories' => $memories->count(),
            'results_count' => $results->count(),
        ]);

        return $results;
    }

    public function delete(string $agentName, string $namespace = 'default', ?string $source = null): int
    {
        Log::debug('InMemory driver: Deleting memories', [
            'agent_name' => $agentName,
            'namespace' => $namespace,
            'source' => $source,
        ]);

        $query = VectorMemory::forAgent($agentName)->inNamespace($namespace);

        if ($source) {
            $query->fromSource($source);
        }

        $count = $query->delete();

        Log::info('InMemory driver: Deleted memories', [
            'agent_name' => $agentName,
            'namespace' => $namespace,
            'source' => $source,
            'count' => $count,
        ]);

        return $count;
    }

    public function getStatistics(string $agentName, string $namespace = 'default'): array
    {
        Log::debug('InMemory driver: Getting statistics', [
            'agent_name' => $agentName,
            'namespace' => $namespace,
        ]);

        $query = VectorMemory::forAgent($agentName)->inNamespace($namespace);

        $totalMemories = $query->count();
        $totalTokens = $query->sum('token_count');

        $providers = $query->select('embedding_provider', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('embedding_provider')
            ->pluck('count', 'embedding_provider')
            ->toArray();

        $sources = $query->whereNotNull('source')
            ->select('source', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('source')
            ->pluck('count', 'source')
            ->toArray();

        return [
            'total_memories' => $totalMemories,
            'total_tokens' => $totalTokens,
            'providers' => $providers,
            'sources' => $sources,
        ];
    }

    public function isAvailable(): bool
    {
        // In-memory driver is always available
        return true;
    }

    public function getName(): string
    {
        return 'inmemory';
    }
}