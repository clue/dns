<?php

namespace React\Tests\Dns\Model;

use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\MessageFactory;

class MessageFactoryTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->factory = new MessageFactory();
    }

    public function testCreateRequestDesiresRecusion()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $request = $this->factory->createRequestForQuery($query);

        $this->assertTrue($request->header->isQuery());
        $this->assertSame(1, $request->header->get('rd'));
    }
}
