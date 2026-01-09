<?php

class CallExpr
{
    public string $name;
    public array $args;

    public function __construct(string $name, array $args = [])
    {
        $this->name = $name;
        $this->args = $args;
    }
}
