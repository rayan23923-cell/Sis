<?php

namespace App\AcademicRules;

/**
 * One versioned academic policy as the engine sees it: no database, no framework.
 * The snapshot is stored with every computed result, so an old result can be recomputed exactly.
 */
final class Policy
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly int $version,
        public readonly string $executor,
        public readonly array $parameters,
        public readonly bool $isOfficial,
        public readonly ?string $sourceReference = null,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'executor' => $this->executor,
            'parameters' => $this->parameters,
            'is_official' => $this->isOfficial,
            'source_reference' => $this->sourceReference,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self(
            (int) $snapshot['id'],
            (string) $snapshot['type'],
            (int) $snapshot['version'],
            (string) $snapshot['executor'],
            (array) $snapshot['parameters'],
            (bool) ($snapshot['is_official'] ?? false),
            $snapshot['source_reference'] ?? null,
        );
    }
}
