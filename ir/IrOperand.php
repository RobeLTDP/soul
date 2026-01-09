<?php

abstract class IrOperand
{
    public static function reg(string $name): IrReg
    {
        return new IrReg($name);
    }

    public static function lit(mixed $value): IrLit
    {
        return new IrLit($value);
    }

    public static function var(string $name): IrVar
    {
        return new IrVar($name);
    }

    public static function label(string $name): IrLabelRef
    {
        return new IrLabelRef($name);
    }
}

final class IrReg extends IrOperand
{
    public function __construct(public string $name) {}
}

final class IrLit extends IrOperand
{
    public function __construct(public mixed $value) {}
}

final class IrVar extends IrOperand
{
    public function __construct(public string $name) {}
}

final class IrLabelRef extends IrOperand
{
    public function __construct(public string $name) {}
}