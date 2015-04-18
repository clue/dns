<?php

namespace React\Tests\Dns\Query;

use React\Tests\Dns\TestCase;
use React\EventLoop\Factory as LoopFactory;
use React\Dns\Query\StreamTransportExecutor;
use React\SocketClient\Connector;
use React\Dns\Resolver\Resolver;
use React\Dns\Query\RejectingExecutor;

class FunctionalStreamTransportExecutorTest extends TestCase
{
    public function setUp()
    {
        $this->loop = LoopFactory::create();
        $this->resolver = new Resolver('8.8.8.8:53', new StreamTransportExecutor(new Connector($this->loop, new Resolver('0.0.0.0:0', new RejectingExecutor()))));
    }

    public function testResolveViaStream()
    {
        $promise = $this->resolver->resolve('igor.io');

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }
}
