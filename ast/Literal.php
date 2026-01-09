<?php

class Literal
{
    public $value;
    public string $type;

    public function __construct($value, string $type)
    {
        $this->value = $value;
        $this->type = $type;
    }
}
