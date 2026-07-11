<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Dto;

final readonly class CleanupDemand
{
    public function __construct(
        public ?int $storage = null,
        public ?string $folder = null,
        public array $fileUids = [],
        public array $identifiers = [],
        public array $configuration = [],
    ) {}

    public function hasFileFilter(): bool
    {
        return $this->storage !== null
            || $this->folder !== null
            || $this->fileUids !== []
            || $this->identifiers !== [];
    }

    public function hasConfigurationFilter(): bool
    {
        return $this->configuration !== [];
    }
}
