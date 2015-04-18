<?php

namespace React\Dns\Resolver;

use React\Cache\ArrayCache;
use React\Dns\Query\CachedExecutor;
use React\Dns\Query\RecordCache;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\DatagramTransportExecutor;
use React\Dns\Query\StreamTransportExecutor;
use React\Datagram\Factory as DatagramFactory;
use React\SocketClient\TcpConnector;
use React\Dns\Query\TimeoutExecutor;

class Factory
{
    public function create($nameserver, LoopInterface $loop)
    {
        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = $this->createExecutor($loop);

        return new Resolver($nameserver, $executor);
    }

    public function createCached($nameserver, LoopInterface $loop)
    {
        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = new CachedExecutor($this->createExecutor($loop), new RecordCache(new ArrayCache()));

        return new Resolver($nameserver, $executor);
    }

    protected function createExecutor(LoopInterface $loop)
    {
        return new RetryExecutor(
            new TimeoutExecutor(
                new SelectiveTransportExecutor(
                    new DatagramTransportExecutor(
                        new DatagramFactory($loop)
                    ),
                    new StreamTransportExecutor(
                        new TcpConnector($loop)
                    )
                ),
                5.0,
                $loop
            )
        );
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
