<?php

declare(strict_types=1);

namespace Konekt\Search;

use Illuminate\Support\Traits\ForwardsCalls;

class SearchFactory
{
    use ForwardsCalls;

    /**
     * Handle dynamic method calls into a new Searcher instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo(
            $this->new(),
            $method,
            $parameters
        );
    }

    /**
     * Returns a new Searcher instance.
     *
     * @return \Konekt\Search\Searcher
     */
    public function new(): Searcher
    {
        return new Searcher();
    }
}
