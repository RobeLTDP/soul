<?php

class BinaryExpr
{
    public $left;
    public string $operator;
    public $right;

    public function __construct($left, string $operator, $right)
    {
        $this->left = $left;
        $this->operator = $operator;
        $this->right = $right;
    }
}
