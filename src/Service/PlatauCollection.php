<?php

namespace App\Service;

use Pagerfanta\Pagerfanta;

/**
 * @template T
 *
 * @extends Pagerfanta<T>
 */
class PlatauCollection extends Pagerfanta
{
    /**
     * Iterate through large lists of resources without having to manually perform the requests to fetch subsequent pages.
     */
    public function autoPagingIterator() : \Generator
    {
        while (true) {
            /* @psalm-suppress MixedAssignment */
            foreach ($this->getCurrentPageResults() as $item) {
                yield $item;
            }

            if (!$this->hasNextPage()) {
                break;
            }

            $this->setCurrentPage($this->getNextPage());
        }
    }
}
