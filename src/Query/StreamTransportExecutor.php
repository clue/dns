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
use React\Stream\Stream;
use React\SocketClient\ConnectorInterface;
use React\Promise\CancellablePromiseInterface;

class StreamTransportExecutor implements ExecutorInterface
{
    private $connector;
    private $parser;
    private $dumper;
    private $messageFactory;

    public function __construct(ConnectorInterface $connector, Parser $parser = null, BinaryDumper $dumper = null, MessageFactory $messageFactory = null)
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

        $this->connector = $connector;
        $this->parser = $parser;
        $this->dumper = $dumper;
        $this->messageFactory = $messageFactory;
    }

    public function query($nameserver, Query $query)
    {
        $request = $this->messageFactory->createRequestForQuery($query);

        $queryData = $this->dumper->toBinary($request);
        $queryData = pack('n', strlen($queryData)) . $queryData;

        $parser = $this->parser;

        $deferred = new Deferred(function ($resolve, $reject) use ($query, &$connecting, &$stream) {
            $reject(new CancellationException(sprintf('DNS query for %s has been cancelled', $query->name)));

            if ($connecting instanceof CancellablePromiseInterface) {
                $connecting->cancel();
            }
            if ($stream !== null) {
                $stream->close();
            }
        });

        $parts = parse_url('tcp://' . $nameserver);
        $connecting = $this->connector->create($parts['host'], $parts['port'])->then(
            function (Stream $s) use ($parser, $queryData, &$stream, $deferred) {
                $stream = $s;
                $buffered = '';

                $stream->on('data', function ($chunk) use (&$buffered, $deferred, $stream, $parser) {
                    $buffered .= $chunk;

                    if (!isset($buffered[1])) {
                        return;
                    }

                    $temp = unpack('nlen', substr($buffered, 0, 2));
                    $expected = $temp['len'] + 2;

                    if (strlen($buffered) < $expected) {
                        return;
                    }

                    $stream->removeAllListeners('close');
                    $stream->close();

                    $response = $parser->parseMessage(substr($buffered, 2));

                    if ($response->header->isTruncated()) {
                        return $deferred->reject(new BadServerException('The server set the truncated bit although we issued a TCP request', 0, new TruncatedResponseException('Truncated response message received')));
                    }

                    $deferred->resolve($response);
                });

                $stream->on('close', function () use ($deferred) {
                    $deferred->reject(new \RuntimeException('Connection to DNS server dropped unexpectedly'));
                });

                $stream->write($queryData);
            },
            function ($e) {
                throw new \RuntimeException('Unable to connect to DNS server', 0, $e);
            }
        )->then(null, function ($e) use ($deferred) {
            $deferred->reject($e);
        });

        return $deferred->promise();
    }
}
