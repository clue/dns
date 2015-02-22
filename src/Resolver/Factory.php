<?php

namespace React\Dns\Resolver;

use React\Cache\ArrayCache;
use React\Dns\Query\Executor;
use React\Dns\Query\CachedExecutor;
use React\Dns\Query\RecordCache;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Dns\Query\RetryExecutor;

class Factory
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function create($nameserver)
    {
        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = $this->createRetryExecutor();

        return new Resolver($nameserver, $executor);
    }

    public function createCached($nameserver, Cache $cache = null)
    {
        if ($cache === null) {
            $cache = new ArrayCache();
        }

        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = $this->createCachedExecutor($cache);

        return new Resolver($nameserver, $executor);
    }

    protected function createExecutor()
    {
        return new Executor($this->loop, new Parser(), new BinaryDumper());
    }

    protected function createRetryExecutor()
    {
        return new RetryExecutor($this->createExecutor());
    }

    protected function createCachedExecutor(Cache $cache)
    {
        return new CachedExecutor($this->createRetryExecutor(), new RecordCache($cache));
    }

    protected function addPortToServerIfMissing($nameserver)
    {
        if (strpos($nameserver, '[') === false && substr_count($nameserver, ':') >= 2) {
            // several colons, but not enclosed in square brackets => enclose IPv6 address in square brackets
            $nameserver = '[' . $nameserver . ']';
        }
        // assume a dummy scheme when checking for the port, otherwise parse_url() fails
        if (parse_url('dummy://' . $nameserver, PHP_URL_PORT) === null) {
            $nameserver .= ':53';
        }

        return $nameserver;
    }
}
