<?php

namespace Vizra\VizraADK\Contracts;

use Illuminate\Support\Collection;
use Vizra\VizraADK\Models\VectorMemory;

interface VectorMemoryDriverInterface
{
    /**
     * Store a vector memory entry.
     *
     * @param VectorMemory $memory
     * @return bool
     */
    public function store(VectorMemory $memory): bool;

    /**
     * Search for similar vectors.
     *
     * @param string $agentName
     * @param array $queryEmbedding
     * @param string $namespace
     * @param int $limit
     * @param float $threshold
     * @return Collection
     */
    public function search(
        string $agentName,
        array $queryEmbedding,
        string $namespace = 'default',
        int $limit = 5,
        float $threshold = 0.7
    ): Collection;

    /**
     * Delete memories by agent and namespace.
     *
     * @param string $agentName
     * @param string $namespace
     * @param string|null $source
     * @return int Number of memories deleted
     */
    public function delete(string $agentName, string $namespace = 'default', ?string $source = null): int;

    /**
     * Get statistics for an agent/namespace.
     *
     * @param string $agentName
     * @param string $namespace
     * @return array
     */
    public function getStatistics(string $agentName, string $namespace = 'default'): array;

    /**
     * Check if the driver is available and configured.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get the driver name.
     *
     * @return string
     */
    public function getName(): string;
}