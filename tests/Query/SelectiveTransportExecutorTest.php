<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\Executor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Tests\Dns\TestCase;
use React\Promise\Deferred;
use React\Dns\Query\TruncatedResponseException;

class SelectiveTransportExecutorTest extends TestCase
{
    public function setUp()
    {
        $this->datagram = $this->getMock('React\Dns\Query\ExecutorInterface');
        $this->stream = $this->getMock('React\Dns\Query\ExecutorInterface');

        $this->executor = new SelectiveTransportExecutor($this->datagram, $this->stream);
    }

    public function testUsesDatagramForUsualQuery()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnValue($this->createPromiseResolved()));

        $this->executor->query('8.8.8.8:53', $query);
    }

    /** @test */
    public function resolveShouldCreateTcpRequestIfRequestIsLargerThan512Bytes()
    {
        $query = new Query(str_repeat('a', 512).'.igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnValue($this->createPromiseResolved()));

        $this->executor->query('8.8.8.8:53', $query);
    }

    /** @test */
    public function resolveShouldCancelWrappedWhenCancelled()
    {
        if (!interface_exists('React\Promise\CancellablePromiseInterface')) {
            $this->markTestSkipped('Skipped missing CancellablePromiseInterface');
        }

        $cancelled = 0;

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnCallback(function () use (&$cancelled) {
                $deferred = new Deferred(function () use (&$cancelled) {
                    ++$cancelled;
                });

                return $deferred->promise();
            }));

        $promise = $this->executor->query('8.8.8.8:53', $query);

        $this->assertEquals(0, $cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);
    }

    /** @test */
    public function resolveShouldRetryWithStreamIfDatagramResponseIsTruncated()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        // TODO:
        $response = new Message();

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnValue($this->createPromiseRejected(new TruncatedResponseException('Truncated response message received'))));

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnValue($this->createPromiseResolved($response)));

        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->then($this->expectCallableOnceWith($response), $this->expectCallableNever());
    }

    /** @test */
    public function resolveShouldFailIfStreamResponseIsTruncated()
    {
        $query = new Query(str_repeat('a', 512).'.igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with('8.8.8.8:53', $query)
            ->will($this->returnValue($this->createPromiseRejected(new \RuntimeException('TRUNCATED'))));

        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    private function returnStandardResponse()
    {
        $callback = function ($data, $response) {
            $this->convertMessageToStandardResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    private function returnTruncatedResponse()
    {
        $callback = function ($data, $response) {
            $this->convertMessageToTruncatedResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    public function convertMessageToStandardResponse(Message $response)
    {
        $response->header->set('qr', 1);
        $response->questions[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131');
        $response->prepare();

        return $response;
    }

    public function convertMessageToTruncatedResponse(Message $response)
    {
        $this->convertMessageToStandardResponse($response);
        $response->header->set('tc', 1);
        $response->prepare();

        return $response;
    }

    private function returnNewConnectionMock()
    {
        $conn = $this->createConnectionMock();

        $callback = function () use ($conn) {
            return $conn;
        };

        return $this->returnCallback($callback);
    }

    private function createConnectionMock()
    {
        $conn = $this->getMock('React\Socket\ConnectionInterface');
        $conn
            ->expects($this->any())
            ->method('on')
            ->with('data', $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($name, $callback) {
                $callback(null);
            }));

        return $conn;
    }

    private function createExecutorMock()
    {
        return $this->getMockBuilder('React\Dns\Query\Executor')
            ->setConstructorArgs(array($this->loop, $this->parser, $this->dumper))
            ->setMethods(array('createConnection'))
            ->getMock();
    }
}
