<?php

namespace App\Services\ListItems;

final readonly class ListAccessContext
{
    public function __construct(
        public int $ownerId,
        public ?int $linkId = null
    ) {}
}
