<?php

class Token
{
    public string $type;
    public $value;
    public int $line;

    public function __construct(string $type, $value = null, int $line = 0)
    {
        $this->type  = $type;
        $this->value = $value;
        $this->line  = $line;
    }

    public function __toString(): string
    {
        return $this->value !== null
            ? "{$this->type}({$this->value})"
            : $this->type;
    }
}
