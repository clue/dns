<?php

namespace React\Dns\Query;

use React\Dns\BadServerException;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\Connection;
use React\Dns\Model\MessageFactory;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Promise\CancellablePromiseInterface;
use React\Datagram\SocketInterface;

class DatagramTransportExecutor implements ExecutorInterface
{
    private $datagramFactory;
    private $parser;
    private $dumper;
    private $messageFactory;

    public function __construct(DatagramFactory $datagramFactory, Parser $parser = null, BinaryDumper $dumper = null, MessageFactory $messageFactory = null)
    {
        if ($parser === null) {
            $parser = new Parser();
        }
        if ($dumper === null) {
            $dumper = new BinaryDumper();
        }
        if ($messageFactory === null) {
            $messageFactory = new MessageFactory();
        }

        $this->datagramFactory = $datagramFactory;
        $this->parser = $parser;
        $this->dumper = $dumper;
        $this->messageFactory = $messageFactory;
    }

    public function query($nameserver, Query $query)
    {
        $request = $this->messageFactory->createRequestForQuery($query);

        $queryData = $this->dumper->toBinary($request);

        $parser = $this->parser;

        $deferred = new Deferred(function ($resolve, $reject) use ($query, &$connecting, &$client) {
            $reject(new CancellationException(sprintf('DNS query for %s has been cancelled', $query->name)));

            if ($connecting instanceof CancellablePromiseInterface) {
                $connecting->cancel();
            }
            if ($client !== null) {
                $client->close();
            }
        });


        $connecting = $this->datagramFactory->createClient($nameserver)->then(
            function (SocketInterface $c) use ($parser, $deferred, $queryData, &$client) {
                $client = $c;
                $client->on('message', function ($data) use ($parser, $client, $deferred) {
                    $client->close();

                    $response = $parser->parseMessage($data);

                    if ($response->header->isTruncated()) {
                        $deferred->reject(new TruncatedResponseException('Truncated response message received'));
                    } else {
                        $deferred->resolve($response);
                    }
                });

                $client->send($queryData);
            },
            function ($e) {
                throw new \RuntimeException('Unable to open datagram socket to DNS server', 0, $e);
            }
        )->then(null, function ($e) use ($deferred) {
            $deferred->reject($e);
        });

        return $deferred->promise();
    }
}
