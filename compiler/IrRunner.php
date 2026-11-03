<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/OpCode.php';

final class IrRunner
{
    private array $regs = [];
    private array $vars = [];
    private array $labels = [];
    private int   $ip = 0;
    private bool $planMode = false;
    private ?PlanRecorder $recorder = null;
    private int $nextHandle = 1;
    private array $walkIters = []; // handle => array{list: array, idx: int}
    private int $maxSteps = 5000000; 
    private array $builtinsWithReturn = [
        'arg'       => true,
        'join'      => true,
        'concat'    => true,
        'length'    => true,
        'walk'      => true,
        'walk_next' => true,
        'stat'      => true,
        'workerId'  => true,
        'dir_next'  => true,
    ];


    public function run(IrProgram $program, bool $trace = false, bool $planMode = false, ?PlanRecorder $recorder = null)
    {
        $this->planMode = $planMode;
        $this->recorder = $recorder;
        $this->regs = [];
        $this->vars = [];
        $this->labels = [];
        $this->ip = 0;
        $this->nextHandle = 1;
        $this->walkIters = [];        
        
            
        // Crear diccionario de labels → índice de instrucción
        foreach ($program->instructions as $i => $inst) {
            if ($inst->opCode === OpCode::LABEL) {
                $label = $inst->operands[0]->name;
                $this->labels[$label] = $i;
            }
        }

        $insts = $program->instructions;
        $cnt   = count($insts);


        $steps = 0;
        while ($this->ip < $cnt) {
            if ($this->planMode && ++$steps > $this->maxSteps) {
                throw new RuntimeException("PLAN aborted: exceeded max steps ($this->maxSteps)");
            }
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

            case OpCode::EXIT:
                // Detiene la ejecución (el bucle principal hace ip++ después).
                $this->ip = PHP_INT_MAX - 1;
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

                $ops = $inst->operands;

                $nameLit = $ops[0];
                $name = $nameLit->value;

                $dst = null;

                // SOLO hay dst si el builtin devuelve valor (y el último operando es un registro)
                $mayHaveDst = isset($this->builtinsWithReturn[$name]);
                if ($mayHaveDst && end($ops) instanceof IrReg) {
                    $dst = array_pop($ops); // quita dst real
                }

                // args = lo que quede después del name
                $args = array_slice($ops, 1);

                $values = [];
                foreach ($args as $a) {
                    if ($a instanceof IrReg) {
                        $values[] = $this->regs[$a->name] ?? null;
                    } elseif ($a instanceof IrLit) {
                        $values[] = $a->value;
                    } elseif ($a instanceof IrVar) {
                        $values[] = $this->vars[$a->name] ?? null;
                    } else {
                        $values[] = null;
                    }
                }

                $result = $this->planMode
                    ? $this->simulateBuiltin($name, $values)
                    : $this->executeBuiltin($name, $values);

                if ($dst instanceof IrReg) {
                    $this->regs[$dst->name] = $result;
                }

                break;





            case OpCode::LABEL:
                break;
        }
    }

private function simulateBuiltin1(string $name, array $args)
{
    if (!$this->recorder) return null;

    switch ($name) {

        case 'copy':
            $src = $args[0] ?? null;
            $dst = $args[1] ?? null;
            $size = @is_file($src) ? @filesize($src) : 0;
            $this->recorder->record('COPY', compact('src','dst','size'));
            return null;

        case 'move':
            $src = $args[0] ?? null;
            $dst = $args[1] ?? null;

            $size = is_file($src) ? filesize($src) : 0;

            $this->recorder->record('MOVE', [
                'src'  => $src,
                'dst'  => $dst,
                'size' => $size
            ]);

            return null;

        case 'mkdir':
            $path = $args[0] ?? null;
            $this->recorder->record('MKDIR', compact('path'));
            return null;

        case 'remove':
            $path = $args[0] ?? null;
            $this->recorder->record('DELETE', compact('path'));
            return null;

        case 'open':
            $path = $args[0] ?? null;
            $this->recorder->record('OPEN', compact('path'));
            return null;

        case 'listdir':
            $path = $args[0] ?? null;

            $this->recorder->record('SCAN_DIR', compact('path'));

            $list = [];

            if (is_string($path) && is_dir($path)) {
                foreach (scandir($path) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $full = $path . '/' . $f;
                    if (is_file($full)) {
                        $list[] = $full;
                    }
                }
            }

            $h = $this->nextHandle++;
            $this->walkIters[$h] = ['list'=>$list,'idx'=>0];
            return $h;
                        
        case 'dir_next':
            $h = $args[0] ?? 0;

            if (!isset($this->walkIters[$h])) return null;

            $it = &$this->walkIters[$h];

            if ($it['idx'] >= count($it['list'])) return null;

            return $it['list'][$it['idx']++];

        case 'rmdir':
            $path = $args[0] ?? null;

            $files = 0;
            $bytes = 0;

            if (is_dir($path)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $files++;
                        $bytes += $f->getSize();
                    }
                }
            }

            $this->recorder->record('DELETE_DIR', [
                'path'  => $path,
                'files' => $files,
                'bytes' => $bytes
            ]);

            return null;


        case 'write':
            $data = $args[1] ?? null;

            $bytes = 0;

            if (is_string($data)) {
                $bytes = strlen($data);
            } elseif (is_int($data)) {
                $bytes = strlen((string)$data);
            }

            $this->recorder->record('WRITE', [
                'bytes' => $bytes
            ]);

            return null;  

        case 'close':
            $this->recorder->record('CLOSE', []);
            return null;

        case 'walk':
            $path = $args[0] ?? null;

            $this->recorder->record('SCAN_DIR', compact('path'));

            $list = [];

            if (is_string($path) && is_dir($path)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $list[] = $f->getPathname();
                    }
                }
            }

            $h = $this->nextHandle++;
            $this->walkIters[$h] = ['list'=>$list,'idx'=>0];

            return $h;



        case 'walk_next':
            return null;



        case 'walk_close':
        case 'dir_close':
            $h = $args[0] ?? 0;
            unset($this->walkIters[$h]);
            return null;
            
        case 'stat':
            $path = $args[0] ?? null;
            return is_string($path) ? (@stat($path) ?: null) : null;

        default:
            return $this->executeBuiltin($name, $args);
    }
}

private function simulateBuiltin(string $name, array $args)
{
    if (!$this->recorder) return null;

    // helper local
    $filesOf = function(string $path): array {
        $out = [];

        if (!is_dir($path)) return $out;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = [
                    'path' => $f->getPathname(),
                    'size' => $f->getSize()
                ];
            }
        }

        return $out;
    };

    switch ($name) {

    // =====================================================
    // COPY
    // =====================================================
    case 'copy':
        $src = $args[0] ?? null;
        $dst = $args[1] ?? null;

        if (is_file($src)) {
            $size = filesize($src);

            $this->recorder->record('COPY', [
                'src'=>$src,
                'dst'=>$dst,
                'size'=>$size
            ]);
        }

        return null;


    // =====================================================
    // MOVE
    // =====================================================
    case 'move':
        $src = $args[0] ?? null;
        $dst = $args[1] ?? null;

        if (is_file($src)) {
            $size = filesize($src);

            $this->recorder->record('MOVE', [
                'src'=>$src,
                'dst'=>$dst,
                'size'=>$size
            ]);
        }

        return null;


    // =====================================================
    // DELETE FILE
    // =====================================================
    case 'remove':
    case 'delete':
        $path = $args[0] ?? null;

        if (is_file($path)) {
            $size = filesize($path);

            $this->recorder->record('DELETE', [
                'path'=>$path,
                'size'=>$size
            ]);
        }

        return null;


    // =====================================================
    // DELETE DIR (recursivo REAL)
    // =====================================================
    case 'rmdir':
    case 'delete_dir':
        $path = $args[0] ?? null;

        $files = $filesOf($path);

        $count = 0;
        $bytes = 0;

        foreach ($files as $f) {
            $count++;
            $bytes += $f['size'];
        }

        $this->recorder->record('DELETE_DIR', [
            'path'=>$path,
            'files'=>$count,
            'bytes'=>$bytes
        ]);

        return null;


    // =====================================================
    // MKDIR
    // =====================================================
    case 'mkdir':
        $path = $args[0] ?? null;

        $this->recorder->record('MKDIR', [
            'path'=>$path
        ]);

        return null;


    // =====================================================
    // WRITE
    // =====================================================
    case 'write':
        $data = $args[1] ?? '';

        $bytes = strlen((string)$data);

        $this->recorder->record('WRITE', [
            'bytes'=>$bytes
        ]);

        return null;


    // =====================================================
    // OPEN / CLOSE
    // =====================================================
    case 'open':
        $path = $args[0] ?? null;

        $this->recorder->record('OPEN', compact('path'));
        return null;

    case 'close':
        $this->recorder->record('CLOSE', []);
        return null;


    // =====================================================
    // LISTDIR (REAL)
    // =====================================================
    case 'listdir':
        $path = $args[0] ?? null;

        $this->recorder->record('SCAN_DIR', compact('path'));

        $list = [];

        if (is_string($path) && is_dir($path)) {
            foreach (scandir($path) as $f) {
                if ($f === '.' || $f === '..') continue;
                $list[] = $path . '/' . $f;
            }
        }

        $h = $this->nextHandle++;
        $this->walkIters[$h] = ['list'=>$list,'idx'=>0];

        return $h;


    case 'dir_next':
        $h = $args[0] ?? 0;

        if (!isset($this->walkIters[$h])) return null;

        $it = &$this->walkIters[$h];

        if ($it['idx'] >= count($it['list'])) return null;

        return $it['list'][$it['idx']++];


    // =====================================================
    // WALK (recursivo REAL)
    // =====================================================
    case 'walk':
        $path = $args[0] ?? null;

        $this->recorder->record('SCAN_DIR', compact('path'));

        $files = [];

        if (is_string($path) && is_dir($path)) {
            foreach ($filesOf($path) as $f) {
                $files[] = $f['path'];
            }
        }

        $h = $this->nextHandle++;
        $this->walkIters[$h] = ['list'=>$files,'idx'=>0];

        return $h;


    case 'walk_next':
        $h = $args[0] ?? 0;

        if (!isset($this->walkIters[$h])) return null;

        $it = &$this->walkIters[$h];

        if ($it['idx'] >= count($it['list'])) return null;

        return $it['list'][$it['idx']++];


    case 'walk_close':
    case 'dir_close':
        $h = $args[0] ?? 0;
        unset($this->walkIters[$h]);
        return null;


    // =====================================================
    // STAT
    // =====================================================
    case 'stat':
        return null; // o eliminar builtin


    default:
        return $this->executeBuiltin($name, $args);
    }
}


    private function executeBuiltin(string $name, array $args)
    {
        switch ($name) {

            case 'arg':
                global $argv;

                // arg() o arg(null) => ""
                $flag = $args[0] ?? null;
                if (!is_string($flag) || $flag === '') {
                    return "";
                }

                $n = count($argv);

                // busca el flag
                for ($i = 0; $i < $n; $i++) {
                    if ($argv[$i] === $flag) {
                        // si no hay valor, devolvemos ""
                        if ($i + 1 >= $n) return "";
                        // si el "valor" es otro flag, devolvemos ""
                        if (is_string($argv[$i + 1]) && str_starts_with($argv[$i + 1], '--')) return "";
                        return $argv[$i + 1];
                    }
                }

                return "";

            case 'join':
                return implode('/', array_filter($args));

            case 'concat':
                return implode('', $args);

            case 'length':
                return strlen((string)$args[0]);

            default:
                // fallback debug
                $printable = array_map(function ($a) {
                    if (is_scalar($a) || $a === null) return $a;
                    return json_encode($a);
                }, $args);

                echo "[builtin] $name(" . implode(', ', $printable) . ")\n";
                return null;
        }
    }



}
