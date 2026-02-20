<?php

namespace App\Services\ListItems;

use App\Models\ListItem;

class ListItemSerializer
{
    public function __construct(
        private readonly ListItemInputNormalizer $normalizer
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(ListItem $item): array
    {
        return [
            'id' => $item->id,
            'owner_id' => $item->owner_id,
            'list_link_id' => $item->list_link_id ? (int) $item->list_link_id : null,
            'type' => $item->type,
            'text' => $item->text,
            'sort_order' => (int) ($item->sort_order ?? 0),
            'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
            'unit' => $item->unit,
            'due_at' => optional($item->due_at)->toISOString(),
            'priority' => $item->type === ListItem::TYPE_TODO
                ? $this->normalizer->normalizePriority($item->priority)
                : null,
            'is_completed' => (bool) $item->is_completed,
            'completed_at' => optional($item->completed_at)->toISOString(),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }
}
