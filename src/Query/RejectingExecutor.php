<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise\Deferred;

class RejectingExecutor implements ExecutorInterface
{
    private $promise;

    public function __construct($reason = null)
    {
        $deferred = new Deferred();
        $deferred->reject($reason);
        $this->promise = $deferred->promise();
    }

    public function query($nameserver, Query $query)
    {
        return $this->promise;
    }
}
