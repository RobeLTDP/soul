<?php

class Program
{
    /** @var array */
    public array $statements;

    public function __construct(array $statements)
    {
        $this->statements = $statements;
    }
}
