<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\Executor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Query\StreamTransportExecutor;
use React\Tests\Dns\TestCase;

class StreamTransportExecutorTest extends TestCase
{
    public function setUp()
    {
        $this->connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $this->parser = $this->getMock('React\Dns\Protocol\Parser');
        $this->dumper = new BinaryDumper();

        $this->executor = new StreamTransportExecutor($this->connector, $this->parser, $this->dumper);
    }

    /** @test */
    public function queryShouldCreateConnectionForQuery()
    {
        $conn = $this->createConnectionMock();

        $this->connector
            ->expects($this->once())
            ->method('create')
            ->with('8.8.8.8', 53)
            ->will($this->returnValue($this->createPromiseResolved($conn)));

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->with($this->equalTo('message'))
            ->will($this->returnValue($this->createResponse()));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $promise = $this->executor->query('8.8.8.8:53', $query);

        $conn->emit('data', array("\x00"));
        $conn->emit('data', array("\x07"));
        $conn->emit('data', array("message"));

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testRejectedConnectonRejectsQuery()
    {
        $this->connector
            ->expects($this->once())
            ->method('create')
            ->with('8.8.8.8', 53)
            ->will($this->returnValue($this->createPromiseRejected(new \RuntimeException('Error'))));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    /** @test */
    public function resolveShouldCloseConnectionWhenCancelled()
    {
        if (!interface_exists('React\Promise\CancellablePromiseInterface')) {
            $this->markTestSkipped('Skipped missing CancellablePromiseInterface');
        }

        $conn = $this->createConnectionMock();
        $conn->expects($this->once())->method('close');

        $this->connector
            ->expects($this->once())
            ->method('create')
            ->with('8.8.8.8', 53)
            ->will($this->returnValue($this->createPromiseResolved($conn)));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->cancel();

        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->logicalAnd(
                $this->isInstanceOf('React\Dns\Query\CancellationException'),
                $this->attribute($this->equalTo('DNS query for igor.io has been cancelled'), 'message')
            )
        );

        $promise->then($this->expectCallableNever(), $errorback);
    }

    /** @test */
    public function resolveShouldFailIfResponseIsTruncated()
    {
        $conn = $this->createConnectionMock();
        $conn->expects($this->once())->method('close');

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->with($this->equalTo('message'))
            ->will($this->returnValue($this->createTruncatedResponse()));

        $this->connector
            ->expects($this->once())
            ->method('create')
            ->with('8.8.8.8', 53)
            ->will($this->returnValue($this->createPromiseResolved($conn)));

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->callback(function($e) {
                return $e instanceof \React\Dns\BadServerException &&
                       'The server set the truncated bit although we issued a TCP request' === $e->getMessage();
            }));

        $query = new Query(str_repeat('a', 512).'.igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $conn->emit('data', array("\x00\x07mess"));
        $conn->emit('data', array('age'));

        $promise->then($this->expectCallableNever(), $mock);
    }

    private function createTruncatedResponse()
    {
        $message = new Message();
        $this->convertMessageToTruncatedResponse($message);

        return $message;
    }

    private function createResponse()
    {
        $message = new Message();
        $this->convertMessageToStandardResponse($message);

        return $message;
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
        return $this->getMockBuilder('React\Stream\Stream')->setMethods(array('write', 'close'))->disableOriginalConstructor()->getMock();
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMock('React\Tests\Dns\CallableStub');
    }
}
