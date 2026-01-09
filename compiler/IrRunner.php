<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/OpCode.php';

final class IrRunner
{
    private array $regs = [];
    private array $vars = [];
    private array $labels = [];
    private int   $ip = 0;

    public function run(IrProgram $program, bool $trace = false): void
    {
        // Crear diccionario de labels → índice de instrucción
        foreach ($program->instructions as $i => $inst) {
            if ($inst->opCode === OpCode::LABEL) {
                $label = $inst->operands[0]->name;
                $this->labels[$label] = $i;
            }
        }

        $insts = $program->instructions;
        $cnt   = count($insts);

        while ($this->ip < $cnt) {
            $inst = $insts[$this->ip];

            if ($trace) {
                $this->traceInstruction($inst);
            }

            $this->execute($inst);

            $this->ip++;
        }
    }

    private function traceInstruction($inst): void
    {
        echo "[IP {$this->ip}] {$inst->opCode} ";

        foreach ($inst->operands as $op) {
            echo $this->operandToString($op) . " ";
        }
        echo "\n";

        echo "  REGS: ";
        print_r($this->regs);

        echo "  VARS: ";
        print_r($this->vars);
        echo "\n";
    }

    private function operandToString($op): string
    {
        // Caso 1: literales
        if ($op instanceof IrLit) {
            return "LIT(" . var_export($op->value, true) . ")";
        }

        // Caso 2: registros
        if ($op instanceof IrReg) {
            return "REG(" . $op->name . ")";
        }

        // Caso 3: variables
        if ($op instanceof IrVar) {
            return "VAR(" . $op->name . ")";
        }

        // Caso 4: labels (robusto)
        // Puede ser IrLabel, IrLabelRef, o cualquier objeto con 'name' o 'value'
        if (is_object($op)) {

            // Si tiene propiedad name
            if (property_exists($op, 'name')) {
                return "LBL(" . $op->name . ")";
            }

            // Si tiene propiedad value
            if (property_exists($op, 'value')) {
                return "LBL(" . var_export($op->value, true) . ")";
            }

            // Fallback genérico para objetos
            return "OBJ(" . var_export($op, true) . ")";
        }

        // Caso 5: string como label
        if (is_string($op)) {
            return "LBL(" . $op . ")";
        }

        // Caso 6: números
        if (is_int($op) || is_float($op)) {
            return "NUM(" . $op . ")";
        }

        // Caso 7: arrays
        if (is_array($op)) {
            return "ARR(" . json_encode($op) . ")";
        }

        // Si aparece algo no esperado
        return "<unknown-op>";
    }


    private function execute($inst): void
    {
        switch ($inst->opCode) {
            case OpCode::LOAD_CONST:
                [$lit, $dst] = $inst->operands;
                $this->regs[$dst->name] = $lit->value;
                break;

            case OpCode::LOAD_VAR:
                [$var, $dst] = $inst->operands;
                $this->regs[$dst->name] = $this->vars[$var->name] ?? 0;
                break;

            case OpCode::STORE_VAR:
                [$src, $var] = $inst->operands;
                $this->vars[$var->name] = $this->regs[$src->name] ?? 0;
                break;

            case OpCode::ADD:
            case OpCode::SUB:
            case OpCode::MUL:
            case OpCode::DIV:
                [$a, $b, $dst] = $inst->operands;
                $x = $this->regs[$a->name] ?? 0;
                $y = $this->regs[$b->name] ?? 0;

                $this->regs[$dst->name] = match ($inst->opCode) {
                    OpCode::ADD => $x + $y,
                    OpCode::SUB => $x - $y,
                    OpCode::MUL => $x * $y,
                    OpCode::DIV => intdiv($x, $y === 0 ? 1 : $y),
                };
                break;

            case OpCode::CMP_LT:
            case OpCode::CMP_LE:
            case OpCode::CMP_GT:
            case OpCode::CMP_GE:
            case OpCode::CMP_EQ:
            case OpCode::CMP_NE:
                [$a, $b, $dst] = $inst->operands;
                $x = $this->regs[$a->name] ?? 0;
                $y = $this->regs[$b->name] ?? 0;

                $this->regs[$dst->name] = match ($inst->opCode) {
                    OpCode::CMP_LT => $x < $y,
                    OpCode::CMP_LE => $x <= $y,
                    OpCode::CMP_GT => $x > $y,
                    OpCode::CMP_GE => $x >= $y,
                    OpCode::CMP_EQ => $x == $y,
                    OpCode::CMP_NE => $x != $y,
                };
                break;

            case OpCode::JMP:
                [$label] = $inst->operands;
                $this->ip = $this->labels[$label->name] - 1;
                break;

            case OpCode::JMP_IF_FALSE:
                [$cond, $label] = $inst->operands;
                if (!($this->regs[$cond->name] ?? 0)) {
                    $this->ip = $this->labels[$label->name] - 1;
                }
                break;

            case OpCode::CALL_BUILTIN:
                // No ejecutamos builtins reales, solo mostramos que se llamarían
                echo "[builtin] " . $inst->operands[0]->value . "\n";
                break;

            case OpCode::LABEL:
                break;
        }
    }
}
