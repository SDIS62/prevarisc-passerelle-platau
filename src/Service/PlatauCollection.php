<?php

namespace App\Service;

use Generator;
use Pagerfanta\Pagerfanta;

class PlatauCollection extends Pagerfanta
{
    /**
     * Iterate through large lists of resources without having to manually perform the requests to fetch subsequent pages.
     */
    public function autoPagingIterator() : Generator
    {
        while (true) {
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
