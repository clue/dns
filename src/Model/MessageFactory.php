<?php

namespace React\Dns\Model;

use React\Dns\Query\Query;

class MessageFactory
{
    public function createRequestForQuery(Query $query)
    {
        $request = new Message();
        $request->header->set('id', $this->generateId());
        $request->header->set('rd', 1);
        $request->questions[] = (array) $query;
        $request->prepare();

        return $request;
    }

    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }
}
