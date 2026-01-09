<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/IrInstruction.php';
require_once SOUL_ROOT . '/ir/IrOperand.php';
require_once SOUL_ROOT . '/ir/OpCode.php';


final class IrExecutor
{
    /** @var array<string,mixed> */
    private array $env = [];   // variables: name => value

    /** @var array<string,mixed> */
    private array $regs = [];  // registers: r0 => value

    /** @var array<string, callable> */
    private array $builtins = [];

    public function __construct(?array $builtins = null)
    {
        // Puedes inyectar builtins desde fuera, pero si no, usamos un set mínimo
        $this->builtins = $builtins ?? $this->defaultBuiltins();
    }

    public function run(IrProgram $program): void
    {
        // Resolver labels si no están
        $labels = $program->labels;
        if (empty($labels)) {
            $labels = $this->buildLabelIndex($program);
        }

        $ip = 0;
        $count = count($program->instructions);

        while ($ip < $count) {
            $inst = $program->instructions[$ip];
            $op = $inst->opCode;
            $ops = $inst->operands;

            switch ($op) {

                // -----------------------------
                // Data movement
                // -----------------------------
                case OpCode::LOAD_CONST: {
                    // LOAD_CONST <lit> <dstReg>
                    [$lit, $dst] = $ops;
                    $this->assertLit($lit, $op);
                    $this->assertReg($dst, $op);

                    $this->regs[$dst->name] = $lit->value;
                    $ip++;
                    break;
                }
                                
                case OpCode::LOAD_INDEX: {
                    [$arrReg, $idxReg, $dstReg] = $ops;

                    $this->assertReg($arrReg, $op);
                    $this->assertReg($idxReg, $op);
                    $this->assertReg($dstReg, $op);

                    $array = $this->regs[$arrReg->name] ?? null;
                    $index = $this->regs[$idxReg->name] ?? null;

                    if (!is_array($array)) {
                        throw new RuntimeException("LOAD_INDEX: el valor no es un array");
                    }

                    if (!is_int($index)) {
                        throw new RuntimeException("LOAD_INDEX: índice no entero");
                    }

                    $this->regs[$dstReg->name] = $array[$index] ?? null;

                    $ip++;
                    break;
                }



                case OpCode::LOAD_VAR: {
                    // LOAD_VAR <$var> <dstReg>
                    [$var, $dst] = $ops;
                    $this->assertVar($var, $op);
                    $this->assertReg($dst, $op);

                    if (!array_key_exists($var->name, $this->env)) {
                        throw new RuntimeException(
                            "IR: Variable '" . $var->name . "' no definida"
                        );
                    }

                    $this->regs[$dst->name] = $this->env[$var->name];
                    $ip++;
                    break;
                }

                case OpCode::STORE_VAR: {
                    // STORE_VAR <srcReg> <$var>
                    [$src, $var] = $ops;
                    $this->assertReg($src, $op);
                    $this->assertVar($var, $op);

                    $this->env[$var->name] = $this->getReg($src->name);
                    $ip++;
                    break;
                }

                // -----------------------------
                // Arithmetic
                // -----------------------------
                case OpCode::ADD:
                case OpCode::SUB:
                case OpCode::MUL:
                case OpCode::DIV: {
                    // OP <leftReg> <rightReg> <dstReg>
                    [$a, $b, $dst] = $ops;
                    $this->assertReg($a, $op);
                    $this->assertReg($b, $op);
                    $this->assertReg($dst, $op);

                    $left = $this->getReg($a->name);
                    $right = $this->getReg($b->name);

                    $result = match ($op) {
                        OpCode::ADD => (is_string($left) || is_string($right))
                            ? (string)$left . (string)$right
                            : $left + $right,
                        OpCode::SUB => $left - $right,
                        OpCode::MUL => $left * $right,
                        OpCode::DIV => ($right == 0)
                            ? throw new RuntimeException("IR: División por cero")
                            : $left / $right,
                        default => null,
                    };

                    $this->regs[$dst->name] = $result;
                    $ip++;
                    break;
                }

                // -----------------------------
                // Comparisons
                // -----------------------------
                case OpCode::CMP_LT:
                case OpCode::CMP_LE:
                case OpCode::CMP_GT:
                case OpCode::CMP_GE:
                case OpCode::CMP_EQ:
                case OpCode::CMP_NE: {
                    // CMP_* <leftReg> <rightReg> <dstReg>
                    [$a, $b, $dst] = $ops;
                    $this->assertReg($a, $op);
                    $this->assertReg($b, $op);
                    $this->assertReg($dst, $op);

                    $left = $this->getReg($a->name);
                    $right = $this->getReg($b->name);

                    $result = match ($op) {
                        OpCode::CMP_LT => $left < $right,
                        OpCode::CMP_LE => $left <= $right,
                        OpCode::CMP_GT => $left > $right,
                        OpCode::CMP_GE => $left >= $right,
                        OpCode::CMP_EQ => $left == $right,
                        OpCode::CMP_NE => $left != $right,
                        default => false,
                    };

                    $this->regs[$dst->name] = $result;
                    $ip++;
                    break;
                }

                // -----------------------------
                // Unary
                // -----------------------------
                case OpCode::NEG:
                case OpCode::NOT: {
                    // NEG <srcReg> <dstReg>
                    // NOT <srcReg> <dstReg>
                    [$src, $dst] = $ops;
                    $this->assertReg($src, $op);
                    $this->assertReg($dst, $op);

                    $v = $this->getReg($src->name);

                    $this->regs[$dst->name] = match ($op) {
                        OpCode::NEG => -$v,
                        OpCode::NOT => !$v,
                        default => null,
                    };

                    $ip++;
                    break;
                }

                // -----------------------------
                // Control flow
                // -----------------------------
                case OpCode::LABEL: {
                    // LABEL <labelRef>
                    // No-op en ejecución
                    $ip++;
                    break;
                }

                case OpCode::JMP: {
                    // JMP <labelRef>
                    [$label] = $ops;
                    $this->assertLabel($label, $op);

                    if (!isset($labels[$label->name])) {
                        throw new RuntimeException(
                            "IR: Label '" . $label->name . "' no encontrado"
                        );
                    }

                    $ip = $labels[$label->name];
                    break;
                }

                case OpCode::JMP_IF_FALSE: {
                    // JMP_IF_FALSE <condReg> <labelRef>
                    [$cond, $label] = $ops;
                    $this->assertReg($cond, $op);
                    $this->assertLabel($label, $op);

                    $condVal = $this->getReg($cond->name);

                    if (!$condVal) {
                        if (!isset($labels[$label->name])) {
                            throw new RuntimeException(
                                "IR: Label '" . $label->name . "' no encontrado"
                            );
                            
                        }
                        $ip = $labels[$label->name];
                    } else {
                        $ip++;
                    }
                    break;
                }

                // -----------------------------
                // Builtin calls
                // -----------------------------
                case OpCode::CALL_BUILTIN: {
                    // CALL_BUILTIN <lit(name)> <argReg>* [<dstReg>]
                    if (count($ops) < 1) {
                        throw new RuntimeException(
                            "IR: Builtin '" . $name . "' no implementado"
                        );
                    }

                    $nameOp = $ops[0];
                    $this->assertLit($nameOp, $op);

                    $name = $nameOp->value;
                    if (!is_string($name)) {
                        throw new RuntimeException("IR: Nombre de builtin inválido");
                    }

                    $dstReg = null;

                    // Heurística: si el último operando es IrReg, lo tratamos como dstReg
                    // y el resto (desde 1 hasta before-last) son args.
                    $argsOperands = array_slice($ops, 1);

                    if (!empty($argsOperands) && end($argsOperands) instanceof IrReg) {
                        $dstReg = array_pop($argsOperands);
                        $this->assertReg($dstReg, $op);
                    }

                    $args = [];
                    foreach ($argsOperands as $aop) {
                        $this->assertReg($aop, $op);
                        $args[] = $this->getReg($aop->name);
                    }

                    if (!isset($this->builtins[$name])) {
                        throw new RuntimeException(
                            "IR: Registro '" . $name . "' no inicializado"
                        );

                        
                    }

                    $result = ($this->builtins[$name])($args);

                    if ($dstReg !== null) {
                        $this->regs[$dstReg->name] = $result;
                    }

                    $ip++;
                    break;
                }

                // -----------------------------
                // Program
                // -----------------------------
                case OpCode::EXIT: {
                    // EXIT [<exitCodeReg>]
                    if (!empty($ops)) {
                        $exitReg = $ops[0];
                        $this->assertReg($exitReg, $op);
                        $code = $this->getReg($exitReg->name);
                        // En esta fase, no hacemos exit($code) para no matar el proceso de test
                        // pero podrías hacerlo si quieres.
                    }
                    return;
                }

                default:
                    throw new RuntimeException("IR: OpCode no soportado: {$op}");
            }
        }
    }

    // --------------------------------------------------
    // Builtins default (temporal, igual espíritu que tu Executor)
    // --------------------------------------------------

    private function defaultBuiltins(): array
    {
        return [
            "exists" => fn($args) => file_exists($args[0]),

            "listdir" => fn($args) => array_values(
                array_filter(
                    array_map(
                        fn($f) => $args[0] . DIRECTORY_SEPARATOR . $f,
                        scandir($args[0])
                    ),
                    fn($p) => !in_array(basename($p), [".", ".."])
                )
            ),

            "basename" => fn($args) => basename($args[0]),
            "len" => fn($args) => count($args[0]),

            "print" => function ($args) {
                echo $args[0] . PHP_EOL;
                return null;
            },

            "copy" => function ($args) {
                [$src, $dst] = $args;

                if (is_dir($dst)) {
                    $dst = rtrim($dst, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . basename($src);
                }

                return copy($src, $dst);
            },

            "move" => function ($args) {
                if (count($args) !== 2) {
                    throw new RuntimeException("move expects 2 arguments");
                }

                [$src, $dst] = $args;

                if (!is_string($src) || !is_string($dst)) {
                    throw new RuntimeException("move expects string paths");
                }

                if (!@rename($src, $dst)) {
                    throw new RuntimeException("move failed: $src -> $dst");
                }

                return true;
            },


            "mkdir" => function ($args) {
                $path = $args[0];
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                }
                return true;
            },

            "write" => function ($args) {
                [$path, $text] = $args;
                $dir = dirname($path);

                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                return file_put_contents($path, $text, FILE_APPEND) !== false;
            },

            "read" => function ($args) {
                $path = $args[0];
                if (!file_exists($path)) {
                    return null;
                }
                return file_get_contents($path);
            },

            "isfile" => fn($args) => is_file($args[0]),
            "isdir" => fn($args) => is_dir($args[0]),
            "size" => fn($args) => file_exists($args[0]) ? filesize($args[0]) : 0,
            "mtime" => fn($args) => file_exists($args[0]) ? filemtime($args[0]) : 0,

            "remove" => function ($args) {
                $path = $args[0];
                if (is_file($path)) {
                    return unlink($path);
                }
                if (is_dir($path)) {
                    return rmdir($path);
                }
                return false;
            },
        ];
    }

    // --------------------------------------------------
    // Label index helper
    // --------------------------------------------------

    private function buildLabelIndex(IrProgram $program): array
    {
        $labels = [];
        foreach ($program->instructions as $i => $inst) {
            if ($inst->opCode === OpCode::LABEL) {
                $label = $inst->operands[0] ?? null;
                if ($label instanceof IrLabelRef) {
                    $labels[$label->name] = $i;
                }
            }
        }
        return $labels;
    }

    // --------------------------------------------------
    // Assertions / getters
    // --------------------------------------------------

    private function getReg(string $name): mixed
    {
        if (!array_key_exists($name, $this->regs)) {
            throw new RuntimeException("IR: Registro '{$name}' no inicializado");
        }
        return $this->regs[$name];
    }

    private function assertReg($op, string $ctx): void
    {
        if (!($op instanceof IrReg)) {
            throw new RuntimeException("IR: Se esperaba registro en {$ctx}");
        }
    }

    private function assertVar($op, string $ctx): void
    {
        if (!($op instanceof IrVar)) {
            throw new RuntimeException("IR: Se esperaba variable en {$ctx}");
        }
    }

    private function assertLit($op, string $ctx): void
    {
        if (!($op instanceof IrLit)) {
            throw new RuntimeException("IR: Se esperaba literal en {$ctx}");
        }
    }

    private function assertLabel($op, string $ctx): void
    {
        if (!($op instanceof IrLabelRef)) {
            throw new RuntimeException("IR: Se esperaba label en {$ctx}");
        }
    }
}
