<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\Executor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Query\DatagramTransportExecutor;
use React\Promise\Deferred;
use React\Tests\Dns\TestCase;

class DatagramTransportExecutorTest extends TestCase
{
    public function setUp()
    {
        $this->datagramFactory = $this->getMockBuilder('React\Datagram\Factory')->disableOriginalConstructor()->getMock();
        $this->parser = $this->getMock('React\Dns\Protocol\Parser');
        $this->dumper = new BinaryDumper();

        $this->executor = new DatagramTransportExecutor($this->datagramFactory, $this->parser, $this->dumper);
    }

    /** @test */
    public function queryShouldCreateUdpRequest()
    {
        $socket = $this->getMock('React\Datagram\SocketInterface');

        $this->datagramFactory
            ->expects($this->once())
            ->method('createClient')
            ->with('8.8.8.8:53')
            ->will($this->returnValue($this->createPromiseResolved($socket)));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query);
    }

    public function testFailIfSocketFails()
    {
        $this->datagramFactory
            ->expects($this->once())
            ->method('createClient')
            ->with('8.8.8.8:53')
            ->will($this->returnValue($this->createPromiseRejected()));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    /** @test */
    public function resolveShouldCloseSocketWhenCancelled()
    {
        if (!interface_exists('React\Promise\CancellablePromiseInterface')) {
            $this->markTestSkipped('Skipped missing CancellablePromiseInterface');
        }

        $socket = $this->getMock('React\Datagram\SocketInterface');
        $socket->expects($this->once())->method('close');

        $this->datagramFactory
            ->expects($this->once())
            ->method('createClient')
            ->with('8.8.8.8:53')
            ->will($this->returnValue($this->createPromiseResolved($socket)));

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
        $socket = $this->getMockBuilder('React\Datagram\Socket')->setMethods(array('close', 'send'))->disableOriginalConstructor()->getMock();
        $socket->expects($this->once())->method('send');
        $socket->expects($this->once())->method('close');

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->with($this->equalTo('message'))
            ->will($this->returnValue($this->createTruncatedResponse()));

        $this->datagramFactory
            ->expects($this->once())
            ->method('createClient')
            ->with('8.8.8.8:53')
            ->will($this->returnValue($this->createPromiseResolved($socket)));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $socket->emit('message', array('message', '8.8.8.8:53', $socket));
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

    private function createTruncatedResponse()
    {
        $message = new Message();
        $this->convertMessageToTruncatedResponse($message);

        return $message;
    }
}
