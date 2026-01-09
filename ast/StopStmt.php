<?php

class StopStmt
{
    /** @var mixed|null Expression */
    public $message;

    public function __construct($message = null)
    {
        $this->message = $message;
    }
}
