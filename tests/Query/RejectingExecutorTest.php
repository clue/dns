<?php

namespace React\Tests\Dns\Query;

use React\Tests\Dns\TestCase;
use React\Dns\Query\NullExecutor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Query\RejectingExecutor;

class RejectingExecutorTest extends TestCase
{
    public function testRejectsWithoutReason()
    {
        $executor = new RejectingExecutor();

        $promise = $executor->query('8.8.8.8:53', new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 0));

        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith(null));
    }

    public function testRejectsWithGivenReason()
    {
        $message = new \RuntimeException('Test');
        $executor = new RejectingExecutor($message);

        $promise = $executor->query('8.8.8.8:53', new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 0));

        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith($message));
    }
}
