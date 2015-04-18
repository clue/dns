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

class SelectiveTransportExecutor implements ExecutorInterface
{
    private $loop;
    private $parser;
    private $dumper;

    public function __construct(ExecutorInterface $datagramExecutor, ExecutorInterface $streamExecutor, $threshold = 512, Dumper $dumper = null, MessageFactory $messageFactory = null)
    {
        if ($dumper === null) {
            $dumper = new BinaryDumper();
        }

        if ($messageFactory === null) {
            $messageFactory = new MessageFactory();
        }

        $this->datagramExecutor = $datagramExecutor;
        $this->streamExecutor = $streamExecutor;
        $this->threshold = $threshold;
        $this->dumper = $dumper;
        $this->messageFactory = $messageFactory;
    }

    public function query($nameserver, Query $query)
    {
        $request = $this->messageFactory->createRequestForQuery($query);

        $queryData = $this->dumper->toBinary($request);

        if (strlen($queryData) > $this->threshold) {
            return $this->streamExecutor->query($nameserver, $query);
        }

        $stream = $this->streamExecutor;
        return $this->datagramExecutor->query($nameserver, $query)->then(
            null,
            function ($e) use ($stream, $nameserver, $query) {
                if ($e instanceof TruncatedResponseException) {
                    return $stream->query($nameserver, $query);
                }
                throw $e;
            }
        );
    }
}
