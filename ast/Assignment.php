<?php

class Assignment
{
    public string $name;
    public $value; // Expression

    public function __construct(string $name, $value)
    {
        $this->name = $name;
        $this->value = $value;
    }
}