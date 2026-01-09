<?php

class ForStmt
{
    public string $varType;
    public string $varName;
    public $iterable;
    public array $body;

    public function __construct(string $varType, string $varName, $iterable, array $body)
    {
        $this->varType = $varType;
        $this->varName = $varName;
        $this->iterable = $iterable;
        $this->body = $body;
    }
}
