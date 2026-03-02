<?php

namespace App\Support\Drive;

final class DocumentDriveDestination
{
    public function __construct(
        public readonly string $storageScope,
        public readonly int $year,
        public readonly string $categorySlug,
        public readonly ?string $entityFolder,
    ) {
    }
}
