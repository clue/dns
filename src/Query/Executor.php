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

class Executor implements ExecutorInterface
{
    private $loop;
    private $parser;
    private $dumper;
    private $messageFactory;

    public function __construct(LoopInterface $loop, Parser $parser, BinaryDumper $dumper, MessageFactory $messageFactory = null)
    {
        if ($messageFactory === null) {
            $messageFactory = new MessageFactory();
        }

        $this->loop = $loop;
        $this->parser = $parser;
        $this->dumper = $dumper;
        $this->messageFactory = $messageFactory;
    }

    public function query($nameserver, Query $query)
    {
        $request = $this->messageFactory->createRequestForQuery($query);

        $queryData = $this->dumper->toBinary($request);
        $transport = strlen($queryData) > 512 ? 'tcp' : 'udp';

        return $this->doQuery($nameserver, $transport, $queryData, $query->name);
    }

    public function doQuery($nameserver, $transport, $queryData, $name)
    {
        $that = $this;
        $parser = $this->parser;
        $loop = $this->loop;

        $response = new Message();
        $deferred = new Deferred(function ($resolve, $reject) use (&$conn, $name) {
            $reject(new CancellationException(sprintf('DNS query for %s has been cancelled', $name)));

            $conn->close();
        });

        $retryWithTcp = function () use ($that, $nameserver, $queryData, $name) {
            return $that->doQuery($nameserver, 'tcp', $queryData, $name);
        };

        $conn = $this->createConnection($nameserver, $transport);
        $conn->on('data', function ($data) use ($retryWithTcp, $conn, $parser, $response, $transport, $deferred) {
            $responseReady = $parser->parseChunk($data, $response);

            if (!$responseReady) {
                return;
            }

            if ($response->header->isTruncated()) {
                if ('tcp' === $transport) {
                    $deferred->reject(new BadServerException('The server set the truncated bit although we issued a TCP request'));
                } else {
                    $conn->end();
                    $deferred->resolve($retryWithTcp());
                }

                return;
            }

            $conn->end();
            $deferred->resolve($response);
        });
        $conn->write($queryData);

        return $deferred->promise();
    }

    protected function createConnection($nameserver, $transport)
    {
        $fd = stream_socket_client("$transport://$nameserver", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($fd, 0);
        $conn = new Connection($fd, $this->loop);

        return $conn;
    }
}
