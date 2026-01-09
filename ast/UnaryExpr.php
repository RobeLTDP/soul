<?php

class UnaryExpr
{
    public string $operator;
    public $expr;

    public function __construct(string $operator, $expr)
    {
        $this->operator = $operator;
        $this->expr = $expr;
    }
}
