<?php

class IfStmt
{
    public $condition;
    public array $thenBlock;
    public ?array $elseBlock;

    public function __construct($condition, array $thenBlock, ?array $elseBlock = null)
    {
        $this->condition = $condition;
        $this->thenBlock = $thenBlock;
        $this->elseBlock = $elseBlock;
    }
}
