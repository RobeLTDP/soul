<?php

require_once SOUL_ROOT . '/ast/Program.php';
require_once SOUL_ROOT . '/ast/VarDecl.php';
require_once SOUL_ROOT . '/ast/Assignment.php';
require_once SOUL_ROOT . '/ast/WhileStmt.php';
require_once SOUL_ROOT . '/ast/IfStmt.php';
require_once SOUL_ROOT . '/ast/CallExpr.php';
require_once SOUL_ROOT . '/ast/Literal.php';
require_once SOUL_ROOT . '/ast/Identifier.php';
require_once SOUL_ROOT . '/ast/BreakStmt.php';
require_once SOUL_ROOT . '/ast/ContinueStmt.php';



final class PlanAnalyzer
{

    private array $bindings = [];
    private array $opaqueFunctions = [
        'join',
        'concat',
        'slice',
        'listdir',
        'dir_next',
    ];

    private array $statefulFunctions = [
        'listdir',
        'dir_next',
    ];
    private array $opaqueVars = [];



    public function analyze(Program $program): void
    {
        echo "SOUL PLAN\n";
        echo "=========\n\n";

        // --------------------------------------------------
        // Phase 1: collect simple bindings (x = literal / identifier)
        // --------------------------------------------------


        $this->collectBindings($program);

        // 1) Intentar detección semántica de alto nivel
        if ($this->detectBulkMove($program)) {
            return;
        }

        // 2) Fallback
        echo "Generic execution plan:\n\n";
        foreach ($program->statements as $stmt) {
            $this->describeStatement($stmt, 0);
        }

        echo "\nSummary:\n";
        echo "- No high-level operation detected\n";
        echo "- Plan only. No execution performed\n";
    }

    // --------------------------------------------------
    // High-level detectors
    // --------------------------------------------------

    private function detectBulkMove(Program $program): bool
    {
        $hasListdir = false;
        $hasDirNext = false;
        $hasMove    = false;
        $hasDirClose = false;

        $srcExpr = null;
        $dstExpr = null;

        foreach ($program->statements as $stmt) {

            // h = listdir(src)
            if ($stmt instanceof Assignment && $stmt->value instanceof CallExpr) {
                if ($stmt->value->name === 'listdir') {
                    $hasListdir = true;
                    $srcExpr = $stmt->value->args[0] ?? null;
                }
            }

            // while (...)
            if ($stmt instanceof WhileStmt) {
                foreach ($stmt->body as $inner) {

                    // name = dir_next(h)
                    if ($inner instanceof Assignment && $inner->value instanceof CallExpr) {
                        if ($inner->value->name === 'dir_next') {
                            $hasDirNext = true;
                        }
                    }

                    // move(from, to)
                    if ($inner instanceof CallExpr && $inner->name === 'move') {
                        $hasMove = true;

                        if (count($inner->args) === 2) {
                            $toVar = $inner->args[1];

                            // Resolver: to -> join(dst, name)
                            if ($toVar instanceof Identifier && isset($this->bindings[$toVar->name])) {
                                $joinExpr = $this->bindings[$toVar->name];

                                if ($joinExpr instanceof CallExpr && $joinExpr->name === 'join') {
                                    $dstExpr = $joinExpr->args[0] ?? null;
                                }
                            }
                        }
                    }

                }
            }

            // dir_close(h)
            if ($stmt instanceof CallExpr && $stmt->name === 'dir_close') {
                $hasDirClose = true;
            }
        }

        if (!($hasListdir && $hasDirNext && $hasMove && $hasDirClose)) {
            return false;
        }

        // --------- PLAN OUTPUT ---------


        // --------------------------------------------------
        // Resolve identifiers using simple bindings
        // --------------------------------------------------

        $srcExpr = $this->resolveExpr($srcExpr);
        $dstExpr = $this->resolveExpr($dstExpr);

        echo "Detected operation:\n";
        echo "- Bulk directory move\n\n";

        echo "Source directory:\n- ";
        $this->describeExprInline($srcExpr);
        echo "\n\n";

        echo "Destination directory:\n- ";
        $this->describeExprInline($dstExpr);
        echo "\n\n";

        // --------------------------------------------------
        // Static analysis: workload & filesystem
        // --------------------------------------------------

        $entryCount = null;
        $srcPath = null;
        $dstPath = null;

        // Resolver paths si son literales
        if ($srcExpr instanceof Literal && is_string($srcExpr->value)) {
            $srcPath = $srcExpr->value;
        }

        if ($dstExpr instanceof Literal && is_string($dstExpr->value)) {
            $dstPath = $dstExpr->value;
        }

        // Contar entradas del directorio fuente (sin ejecutar el programa)
        if ($srcPath !== null && is_dir($srcPath) && is_readable($srcPath)) {
            $count = 0;
            $dh = @opendir($srcPath);
            if ($dh !== false) {
                while (($entry = readdir($dh)) !== false) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $count++;
                }
                closedir($dh);
                $entryCount = $count;
            }
        }

        // Mostrar carga estimada
        echo "Estimated workload:\n";
        if ($entryCount !== null) {
            echo "- Entries to process: ~" . number_format($entryCount) . "\n\n";
        } else {
            echo "- Entries to process: unknown (cannot inspect source directory)\n\n";
        }

        // Análisis de filesystem (rename O(1) vs copia)
        echo "Filesystem analysis:\n";

        if ($srcPath !== null && $dstPath !== null &&
            file_exists($srcPath) && file_exists($dstPath)) {

            $srcStat = @stat($srcPath);
            $dstStat = @stat($dstPath);

            if ($srcStat !== false && $dstStat !== false) {
                if ($srcStat['dev'] === $dstStat['dev']) {
                    echo "- Source and destination are on the same filesystem\n";
                    echo "- rename() will be atomic and O(1)\n\n";
                } else {
                    echo "- Source and destination are on different filesystems\n";
                    echo "- rename() would FAIL with EXDEV\n";
                    echo "- Program has no cross-filesystem fallback\n";
                    echo "- Execution would abort at runtime\n\n";
                }
            } else {
                echo "- Unable to stat source or destination directory\n\n";
            }

        } else {
            echo "- Filesystem comparison not possible\n\n";
        }




        echo "Execution plan:\n";
        echo "- Open directory\n";
        echo "- Iterate entries\n";
        echo "- For each entry:\n";
        echo "    - Build source path\n";
        echo "    - Build destination path\n";
        echo "    - Rename entry (atomic, no copy)\n";
        echo "- Close directory\n\n";

        echo "System impact:\n";
        echo "- Filesystem mutation: YES\n";
        echo "- Data copied: NO (rename)\n";
        echo "- Kernel syscalls: openat, getdents64, rename, close\n\n";

        echo "Plan only. Execution requires 'soul run'.\n";

        return true;
    }

    private function resolveExpr($expr)
    {
        if ($expr instanceof Identifier) {

            // Si la variable es opaca, no se resuelve nunca
            if (isset($this->opaqueVars[$expr->name])) {
                return $expr;
            }

            // Resolver solo si es segura
            if (isset($this->bindings[$expr->name])) {
                return $this->bindings[$expr->name];
            }
        }

        return $expr;
    }



    // --------------------------------------------------
    // Fallback descriptive plan (tu versión original)
    // --------------------------------------------------

    private function describeStatement($stmt, int $indent): void
    {
        $pad = str_repeat('  ', $indent);

        if ($stmt instanceof VarDecl) {
            echo $pad . "- Assign ";
            $this->describeExprInline($stmt->value);
            echo " to '{$stmt->name}'\n";
            return;
        }

        if ($stmt instanceof Assignment) {

            // Guardar binding si es literal o identificador simple
            if ($stmt->value instanceof Literal || $stmt->value instanceof Identifier) {
                $this->bindings[$stmt->name] = $stmt->value;
            }

            echo $pad . "- Set '{$stmt->name}' to ";
            $this->describeExprInline($stmt->value);
            echo "\n";
            return;
        }

        if ($stmt instanceof ContinueStmt) {
            echo $pad . "- Skip iteration\n";
            return;
        }


        if ($stmt instanceof CallExpr) {
            echo $pad . "- Call {$stmt->name}()\n";
            return;
        }

        if ($stmt instanceof WhileStmt) {
            echo $pad . "- Loop\n";
            foreach ($stmt->body as $s) {
                $this->describeStatement($s, $indent + 1);
            }
            echo $pad . "- End loop\n";
            return;
        }

        if ($stmt instanceof IfStmt) {
            echo $pad . "- Conditional\n";
            foreach ($stmt->thenBlock as $s) {
                $this->describeStatement($s, $indent + 1);
            }
            return;
        }

        if ($stmt instanceof BreakStmt) {
            echo $pad . "- Exit loop\n";
            return;
        }

        echo $pad . "- (Unsupported statement)\n";
    }

    private function describeExprInline($expr): void
    {
        // Resolver binding si existe
        if ($expr instanceof Identifier && isset($this->bindings[$expr->name])) {
            $expr = $this->bindings[$expr->name];
        }

        if ($expr instanceof Literal) {
            echo is_string($expr->value)
                ? '"' . $expr->value . '"'
                : $expr->value;
            return;
        }

        if ($expr instanceof Identifier) {
            echo $expr->name;
            return;
        }

        if ($expr instanceof Identifier) {
            if (isset($this->opaqueVars[$expr->name])) {
                echo $expr->name;
                return;
            }
        }


        if ($expr instanceof CallExpr) {

            if (in_array($expr->name, $this->opaqueFunctions, true)) {
                echo "<expr>";
                return;
            }

            echo $expr->name . "(";
            $first = true;
            foreach ($expr->args as $arg) {
                if (!$first) echo ", ";
                $this->describeExprInline($arg);
                $first = false;
            }
            echo ")";
            return;
        }

        if ($expr instanceof Identifier && isset($this->opaqueVars[$expr->name])) {
            echo $expr->name;
            return;
        }



        echo "<expr>";
    }

    private function collectBindings(Program $program): void
    {
        foreach ($program->statements as $stmt) {
            $this->collectBindingsFromStmt($stmt);
        }
    }

    private function collectBindingsFromStmt($stmt): void
    {
        if ($stmt instanceof VarDecl || $stmt instanceof Assignment) {

            // Guardar binding
            $this->bindings[$stmt->name] = $stmt->value;

            // Marcar variable como opaca si proviene de función stateful
            if ($stmt->value instanceof CallExpr) {
                if (in_array($stmt->value->name, $this->statefulFunctions, true)) {
                    $this->opaqueVars[$stmt->name] = true;
                }
            }

            return;
        }

        if ($stmt instanceof WhileStmt) {
            foreach ($stmt->body as $inner) {
                $this->collectBindingsFromStmt($inner);
            }
            return;
        }

        if ($stmt instanceof IfStmt) {
            foreach ($stmt->thenBlock as $inner) {
                $this->collectBindingsFromStmt($inner);
            }
            if ($stmt->elseBlock) {
                foreach ($stmt->elseBlock as $inner) {
                    $this->collectBindingsFromStmt($inner);
                }
            }
            return;
        }
    }




}
