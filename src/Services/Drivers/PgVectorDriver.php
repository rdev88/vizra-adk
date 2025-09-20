<?php

namespace Vizra\VizraADK\Services\Drivers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Vizra\VizraADK\Contracts\VectorMemoryDriverInterface;
use Vizra\VizraADK\Models\VectorMemory;

class PgVectorDriver implements VectorMemoryDriverInterface
{
    public function store(VectorMemory $memory): bool
    {
        try {
            if (!$this->isAvailable()) {
                throw new RuntimeException('PgVector driver requires PostgreSQL connection');
            }

            // For PostgreSQL with pgvector, update the vector column separately
            // This assumes the memory record was already created without the embedding
            DB::table('agent_vector_memories')
                ->where('id', $memory->id)
                ->update(['embedding' => '[' . implode(',', $memory->embedding_vector) . ']']);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to store vector in PostgreSQL', [
                'error' => $e->getMessage(),
                'memory_id' => $memory->id,
                'agent_name' => $memory->agent_name,
            ]);
            throw new RuntimeException('Failed to store vector in PostgreSQL: ' . $e->getMessage());
        }
    }

    public function search(
        string $agentName,
        array $queryEmbedding,
        string $namespace = 'default',
        int $limit = 5,
        float $threshold = 0.7
    ): Collection {
        try {
            if (!$this->isAvailable()) {
                throw new RuntimeException('PgVector driver requires PostgreSQL connection');
            }

            $embeddingStr = '[' . implode(',', $queryEmbedding) . ']';

            $results = DB::select('
                SELECT
                    id, agent_name, namespace, content, metadata, source, source_id,
                    embedding_provider, embedding_model, created_at,
                    1 - (embedding <=> ?) as similarity
                FROM agent_vector_memories
                WHERE agent_name = ?
                    AND namespace = ?
                    AND 1 - (embedding <=> ?) >= ?
                ORDER BY embedding <=> ?
                LIMIT ?
            ', [$embeddingStr, $agentName, $namespace, $embeddingStr, $threshold, $embeddingStr, $limit]);

            $collection = collect($results)->map(function ($result) {
                $result->metadata = json_decode($result->metadata, true);
                return $result;
            });

            Log::debug('PostgreSQL vector search completed', [
                'agent_name' => $agentName,
                'namespace' => $namespace,
                'results_count' => $collection->count(),
                'query_dimensions' => count($queryEmbedding),
                'threshold' => $threshold,
            ]);

            return $collection;
        } catch (\Exception $e) {
            Log::error('PostgreSQL vector search failed', [
                'error' => $e->getMessage(),
                'agent_name' => $agentName,
                'namespace' => $namespace,
            ]);
            throw new RuntimeException('PostgreSQL vector search failed: ' . $e->getMessage());
        }
    }

    public function delete(string $agentName, string $namespace = 'default', ?string $source = null): int
    {
        try {
            if (!$this->isAvailable()) {
                throw new RuntimeException('PgVector driver requires PostgreSQL connection');
            }

            $query = VectorMemory::forAgent($agentName)->inNamespace($namespace);

            if ($source) {
                $query->fromSource($source);
            }

            $count = $query->delete();

            Log::info('Deleted vectors from PostgreSQL', [
                'agent_name' => $agentName,
                'namespace' => $namespace,
                'source' => $source,
                'count' => $count,
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to delete vectors from PostgreSQL', [
                'error' => $e->getMessage(),
                'agent_name' => $agentName,
                'namespace' => $namespace,
                'source' => $source,
            ]);
            throw new RuntimeException('PostgreSQL vector deletion failed: ' . $e->getMessage());
        }
    }

    public function getStatistics(string $agentName, string $namespace = 'default'): array
    {
        try {
            if (!$this->isAvailable()) {
                throw new RuntimeException('PgVector driver requires PostgreSQL connection');
            }

            $query = VectorMemory::forAgent($agentName)->inNamespace($namespace);

            $totalMemories = $query->count();
            $totalTokens = $query->sum('token_count');

            $providers = $query->select('embedding_provider', DB::raw('count(*) as count'))
                ->groupBy('embedding_provider')
                ->pluck('count', 'embedding_provider')
                ->toArray();

            $sources = $query->whereNotNull('source')
                ->select('source', DB::raw('count(*) as count'))
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray();

            return [
                'total_memories' => $totalMemories,
                'total_tokens' => $totalTokens,
                'providers' => $providers,
                'sources' => $sources,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get PostgreSQL statistics', [
                'error' => $e->getMessage(),
                'agent_name' => $agentName,
                'namespace' => $namespace,
            ]);

            return [
                'total_memories' => 0,
                'total_tokens' => 0,
                'providers' => [],
                'sources' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        try {
            $connectionName = DB::getDefaultConnection();
            $driver = DB::connection($connectionName)->getDriverName();

            if ($driver !== 'pgsql') {
                return false;
            }

            // Check if pgvector extension is available
            $result = DB::select("SELECT 1 FROM pg_extension WHERE extname = 'vector'");

            return !empty($result);
        } catch (\Exception $e) {
            Log::debug('PgVector availability check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'pgvector';
    }
}
