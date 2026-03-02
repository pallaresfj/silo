<?php

namespace App\Support\Drive;

final class DriveImportClassification
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $storageScope,
        public readonly int $year,
        public readonly string $categoryId,
        public readonly ?string $entityId,
        public readonly string $status,
        public readonly string $confidence,
        public readonly string $title,
        public readonly string $fileName,
        public readonly array $metadata = [],
    ) {
    }
}
