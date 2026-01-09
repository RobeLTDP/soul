<?php

class ParallelStmt
{
    public int $workers;
    public array $body;

    public function __construct(int $workers, array $body)
    {
        $this->workers = $workers;
        $this->body = $body;
    }
}
