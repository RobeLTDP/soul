<?php

class WhileStmt
{
    public $condition;
    public array $body;

    public function __construct($condition, array $body)
    {
        $this->condition = $condition;
        $this->body = $body;
    }
}
