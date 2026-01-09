<?php

require_once SOUL_ROOT . '/lexer/Lexer.php';
require_once SOUL_ROOT . '/parser/Parser.php';
require_once SOUL_ROOT . '/semantic/Analyzer.php';
require_once SOUL_ROOT . '/compiler/Lowerer.php';
require_once SOUL_ROOT . '/compiler/CEmitter.php';
require_once SOUL_ROOT . '/compiler/Optimizer.php';


// Si tu Lowerer/CEmitter están en el mismo directorio "compiler", ajusta require_once según tu estructura real.
// Aquí asumo:
// - compiler/Lowerer.php
// - compiler/CEmitter.php

final class Compiler
{
    private bool $dumpIR;
    private bool $dumpC;
    private bool $trace;

    public function __construct(bool $dumpIR = false, bool $dumpC = false, bool $trace = false)
    {
        $this->dumpIR = $dumpIR;
        $this->dumpC  = $dumpC;
        $this->trace  = $trace;
    }

    /**
     * Compila un fichero .soul a binario nativo usando gcc.
     *
     * @param string $inputPath ruta a .soul
     * @param string $outDir directorio de salida
     * @param bool $runGcc si true, compila con gcc
     * @return array{cPath:string, binPath:?string}
     */
    public function compileFile(string $inputPath, string $outDir, bool $runGcc = true): array
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException("No existe el fichero: $inputPath");
        }

        $code = file_get_contents($inputPath);
        if ($code === false) {
            throw new RuntimeException("No se pudo leer: $inputPath");
        }

        if (!is_dir($outDir)) {
            if (!mkdir($outDir, 0777, true) && !is_dir($outDir)) {
                throw new RuntimeException("No se pudo crear el directorio de salida: $outDir");
            }
        }

        // ---------------------------------------------------------
        // 1. Calcular ruta relativa dentro de /library
        // ---------------------------------------------------------
        $inputReal = realpath($inputPath);
        $libraryRoot = realpath(SOUL_ROOT . '/library');

        if (str_starts_with($inputReal, $libraryRoot)) {
            // Ej: /home/.../library/examples/move.soul → /examples/
            $relativeDir = dirname(substr($inputReal, strlen($libraryRoot)));
        } else {
            // Scripts fuera de /library → van en raíz de /out
            $relativeDir = '';
        }

        // Normalizar
        $relativeDir = trim($relativeDir, "/");

        // Nombre base sin extensión
        $baseName = pathinfo($inputPath, PATHINFO_FILENAME);

        // ---------------------------------------------------------
        // 2. Carpeta destino final dentro de /out
        // ---------------------------------------------------------
        $finalOutDir = rtrim($outDir, '/') . ($relativeDir !== '' ? '/' . $relativeDir : '');

        if (!is_dir($finalOutDir)) {
            if (!mkdir($finalOutDir, 0777, true) && !is_dir($finalOutDir)) {
                throw new RuntimeException("No se pudo crear el directorio de salida: $finalOutDir");
            }
        }

        // ---------------------------------------------------------
        // 3. Rutas finales de C y binario (mirror 1:1)
        // ---------------------------------------------------------
        $cPath  = $finalOutDir . '/' . $baseName . '.c';
        $binPath = $finalOutDir . '/' . $baseName;


        // Frontend
        echo "== LEXER ==\n";
        $lexer = new Lexer($code);
        $tokens = $lexer->tokenize();
        echo "Tokens OK\n";

        echo "== PARSER ==\n";
        $parser = new Parser($tokens);
        $ast = $parser->parseProgram();
        echo "Parser OK\n";

        $parseErrors = $parser->getErrors();
        if (!empty($parseErrors)) {
            $msg = "PARSER ERRORS:\n" . implode("\n", $parseErrors);
            throw new RuntimeException($msg);
        }


        echo "== ANALYZER ==\n";
        $analyzer = new Analyzer();
        $analyzer->analyze($ast);
        echo "Analyzer OK\n";

        $semErrors = $analyzer->getErrors();
        if (!empty($semErrors)) {
            $msg = "SEMANTIC ERRORS:\n" . implode("\n", $semErrors);
            throw new RuntimeException($msg);
        }
        // LOWERER
        echo "== LOWERER ==\n";
        $lowerer = new Lowerer();
        $ir = $lowerer->lower($ast);
        echo "Lowerer OK\n";
        // --------------------------------------------------
        // DUMP IR
        // --------------------------------------------------
        if ($this->dumpIR) {
            echo "===== IR DUMP =====\n";

            foreach ($ir->instructions as $i => $inst) {
                $op  = $inst->opCode;
                $ops = [];

                foreach ($inst->operands as $o) {
                    if ($o instanceof IrReg)        $ops[] = $o->name;
                    elseif ($o instanceof IrVar)    $ops[] = '$'.$o->name;
                    elseif ($o instanceof IrLit)    $ops[] = json_encode($o->value);
                    elseif ($o instanceof IrLabelRef) $ops[] = '|'.$o->name.'|';
                    else $ops[] = '<?>';
                }

                printf("%03d: %-12s %s\n", $i, $op, implode(", ", $ops));
            }

            echo "===== END IR DUMP =====\n";
            // No seguimos a optimizer/emitter/gcc
            return ['cPath' => null, 'binPath' => null];
        }


        // OPTIMIZER sobre IR
        echo "== OPTIMIZER ==\n";
        $optimizer = new Optimizer();
        $optimizer->optimize($ir);
        echo "Optimizer OK\n";

        // Si se pidió trace, ejecutamos IR en modo intérprete y salimos
        if ($this->trace) {
            require_once SOUL_ROOT . '/compiler/IrRunner.php';
            echo "== TRACE EXECUTION ==\n";
            $runner = new IrRunner();
            $runner->run($ir, trace: true);
            exit(0);
        }


        // EMITTER
        echo "== EMITTER ==\n";
        $emitter = new CEmitter();
        $cCode = $emitter->emit($ir);
        echo "Emitter OK\n";
        // --------------------------------------------------
        // DUMP C CODE
        // --------------------------------------------------
        if ($this->dumpC) {
            echo "===== C DUMP =====\n";
            echo $cCode;
            echo "\n===== END C DUMP =====\n";

            // No seguimos a escribir fichero ni a gcc
            return ['cPath' => null, 'binPath' => null];
        }


        if (file_put_contents($cPath, $cCode) === false) {
            throw new RuntimeException("No se pudo escribir: $cPath");
        }

        // gcc
        if ($runGcc) {
            $this->compileWithGcc($cPath, $binPath);
            return ['cPath' => $cPath, 'binPath' => $binPath];
        }

        return ['cPath' => $cPath, 'binPath' => null];
    }

    private function compileWithGcc(string $cPath, string $binPath): void
    {
        // Importante: esto asume que ejecutas el CLI dentro de Linux/WSL.
        // En Windows sin gcc en PATH, fallará (y está bien).
        $cmd = sprintf(
            'gcc -O3 %s -o %s 2>&1',
            escapeshellarg($cPath),
            escapeshellarg($binPath)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "gcc falló (exit=$exitCode):\n" . implode("\n", $output)
            );
        }
    }
}
