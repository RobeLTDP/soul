<?php

class VarDecl
{
    public string $type;
    public string $name;
    public $value; // Expression

    public function __construct(string $type, string $name, $value)
    {
        $this->type = $type;
        $this->name = $name;
        $this->value = $value;
    }
}
