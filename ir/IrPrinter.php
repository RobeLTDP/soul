<?php

/**
 * Pretty printer seguro del IR.
 */
class IrPrinter
{
    /**
     * @param IrProgram $p
     */
    public static function print(IrProgram $p): void
    {
        /** @var IrInstruction $inst */
        foreach ($p->instructions as $inst) {

            $s = "";

            // RESULT: solo si existe y es IrTemp
            if (isset($inst->result) && $inst->result instanceof IrTemp) {
                $s .= "t{$inst->result->id} = ";
            }

            $op = $inst->opCode;
            $ops = $inst->operands;

            switch ($op) {

                case OpCode::MOV:
                    // MOV dst src
                    $dst = self::operandToStr($ops[0] ?? null);
                    $src = self::operandToStr($ops[1] ?? null);
                    $s .= "MOV {$dst} {$src}";
                    break;

                case OpCode::CALL_BUILTIN:
                    // CALL_BUILTIN name arg1 arg2 ...
                    $name = ($ops[0] instanceof IrLit) ? $ops[0]->value : "?";
                    $args = array_slice($ops, 1);
                    $argStr = implode(" ", array_map([self::class, 'operandToStr'], $args));
                    $argStr = $argStr ? " " . $argStr : "";
                    $s .= "CALL_BUILTIN {$name}{$argStr}";
                    break;

                case OpCode::ADD:
                case OpCode::SUB:
                case OpCode::MUL:
                case OpCode::DIV:
                case OpCode::CMP_LT:
                case OpCode::CMP_LE:
                case OpCode::CMP_GT:
                case OpCode::CMP_GE:
                case OpCode::CMP_EQ:
                case OpCode::CMP_NE:
                    $argStr = implode(" ", array_map([self::class, 'operandToStr'], $ops));
                    $s .= "{$op} {$argStr}";
                    break;

                case OpCode::JMP:
                case OpCode::JMP_IF_FALSE:
                    $target = self::operandToStr($ops[0] ?? null);
                    $s .= "{$op} {$target}";
                    break;

                case OpCode::LABEL:
                    $label = ($ops[0] instanceof IrLabelRef) ? $ops[0]->name : "?";
                    $s .= "{$label}:";
                    break;

                default:
                    // fallback seguro
                    $argStr = implode(" ", array_map([self::class, 'operandToStr'], $ops));
                    $s .= "{$op} {$argStr}";
            }

            echo $s . "\n";
        }
    }

    /**
     * Convierte cualquier operando a string.
     *
     * @param mixed $op
     * @return string
     */
    private static function operandToStr($op): string
    {
        if ($op instanceof IrTemp) {
            return "t{$op->id}";
        }

        if ($op instanceof IrLit) {
            return is_string($op->value)
                ? '"' . $op->value . '"'
                : (string)$op->value;
        }

        if ($op instanceof IrLabelRef) {
            return $op->name;
        }

        if (is_scalar($op)) {
            return (string)$op;
        }

        if ($op === null) {
            return "?";
        }

        return "?";
    }
}
