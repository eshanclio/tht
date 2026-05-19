<?php

namespace App\Domains\Parking\Data;

final readonly class AllocationResult
{
    /**
     * @param  array<int>  $spotIds      Spot IDs to claim. 1 for car/motorcycle, 3 for van (in order: left, mid, right).
     * @param  int|null    $windowId     Set only for vans — the van_windows row to claim.
     * @param  int|null    $sectionId    Set only for vans — section of the picked window.
     * @param  int|null    $gridRow      Set only for vans — row of the picked window.
     * @param  int|null    $startColumn  Set only for vans — leftmost column of the picked window.
     */
    public function __construct(
        public array $spotIds,
        public ?int $windowId = null,
        public ?int $sectionId = null,
        public ?int $gridRow = null,
        public ?int $startColumn = null,
    ) {}
}
