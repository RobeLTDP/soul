<?php

class CheckExpr
{
    public $expr;
    public $onFail;

   /** @var StopStmt */
    public StopStmt $onError;


    public function __construct($expr, StopStmt $onFail)
    {
        $this->expr = $expr;
        $this->onFail = $onFail;
    }
}