<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/IrInstruction.php';
require_once SOUL_ROOT . '/ir/IrOperand.php';
require_once SOUL_ROOT . '/ir/OpCode.php';

// AST
require_once SOUL_ROOT . '/ast/ForStmt.php';
require_once SOUL_ROOT . '/ast/Program.php';
require_once SOUL_ROOT . '/ast/VarDecl.php';
require_once SOUL_ROOT . '/ast/Assignment.php';
require_once SOUL_ROOT . '/ast/WhileStmt.php';
require_once SOUL_ROOT . '/ast/IfStmt.php';
require_once SOUL_ROOT . '/ast/CallExpr.php';
require_once SOUL_ROOT . '/ast/BinaryExpr.php';
require_once SOUL_ROOT . '/ast/UnaryExpr.php';
require_once SOUL_ROOT . '/ast/Literal.php';
require_once SOUL_ROOT . '/ast/Identifier.php';
require_once SOUL_ROOT . '/ast/BreakStmt.php';
require_once SOUL_ROOT . '/ast/StopStmt.php';
require_once SOUL_ROOT . '/ast/ContinueStmt.php';
require_once SOUL_ROOT . '/ast/ReturnStmt.php';
require_once SOUL_ROOT . '/ast/ParallelStmt.php';



final class Lowerer
{
    private IrProgram $program;
    private int $regCounter = 0;
    private int $labelCounter = 0;
    private bool $hasParallel = false;


    /** @var string[] */
    private array $breakStack = [];
    /** @var string[] */
    private array $continueStack = [];

    private ?int $parallelWorkers = null;
    private bool $inParallel = false;


    public function lower(Program $ast): IrProgram
    {
        $this->program = new IrProgram();
        $this->regCounter = 0;
        $this->labelCounter = 0;

        foreach ($ast->statements as $stmt) {
            if ($stmt instanceof ParallelStmt) {
                $this->hasParallel = true;
                break;
            }
        }


        //foreach ($ast->statements as $stmt) {
        //    $this->lowerStatement($stmt);
        //}

        $this->lowerTopLevel($ast->statements);


        $this->program->add(new IrInstruction(OpCode::EXIT));        
        return $this->program;
    }

    // --------------------------------------------------
    // Helpers
    // --------------------------------------------------

    private function newReg(): IrReg
    {
        return IrOperand::reg('r' . $this->regCounter++);
    }


    
    private function newLabel(string $prefix = 'L'): string
    {
        return $prefix . $this->labelCounter++;
    }

    // --------------------------------------------------
    // Statements
    // --------------------------------------------------

    private function lowerStatement($stmt): void
    {
        if ($stmt instanceof VarDecl) {
            $valueReg = $this->lowerExpression($stmt->value);
            $this->program->add(
                new IrInstruction(
                    OpCode::STORE_VAR,
                    [$valueReg, IrOperand::var($stmt->name)]
                )
            );
            return;
        }

        if ($stmt instanceof Assignment) {
            $valueReg = $this->lowerExpression($stmt->value);
            $this->program->add(
                new IrInstruction(
                    OpCode::STORE_VAR,
                    [$valueReg, IrOperand::var($stmt->name)]
                )
            );
            return;
        }

        if ($stmt instanceof BreakStmt) {
            if (empty($this->breakStack)) {
                throw new RuntimeException("break fuera de bucle");
            }

            $this->program->add(
                new IrInstruction(
                    OpCode::JMP,
                    [IrOperand::label(end($this->breakStack))]
                )
            );
            return;
        }

        if ($stmt instanceof ReturnStmt) {
            // Termina el programa: EXIT sin operando devuelve 0.
            $this->program->add(new IrInstruction(OpCode::EXIT));
            return;
        }

        if ($stmt instanceof ContinueStmt) {
            if (empty($this->continueStack)) {
                throw new RuntimeException("continue fuera de bucle");
            }

            $this->program->add(
                new IrInstruction(
                    OpCode::JMP,
                    [IrOperand::label(end($this->continueStack))]
                )
            );
            return;
        }

        if ($stmt instanceof WhileStmt) {
            $this->lowerWhile($stmt);
            return;
        }

        if ($stmt instanceof IfStmt) {
            $this->lowerIf($stmt);
            return;
        }

        if ($stmt instanceof ParallelStmt) {
            // En top-level esto se gestiona en lowerTopLevel().
            // Si apareciera dentro de otros bloques (if/while/for),
            // lo emitimos también de forma estructural.
            $this->lowerParallelStmt($stmt);
            return;
        }

            

        if ($stmt instanceof ForStmt) {
            $this->lowerFor($stmt);
            return;
        }

        if ($stmt instanceof StopStmt) {
            if (empty($this->breakStack)) {
                throw new RuntimeException("break fuera de un bucle");
            }

            $target = end($this->breakStack);

            $this->program->add(
                new IrInstruction(
                    OpCode::JMP,
                    [IrOperand::label($target)]
                )
            );
            return;
        }

        if ($stmt instanceof CallExpr) {
            // llamada como statement (sin valor de retorno)
            $this->lowerCall($stmt, null);
            return;
        }

        // Otros statements se añadirán después
        // (For, Parallel, Stop, Check…)

        throw new RuntimeException(
            'Lowering no soportado para ' . get_class($stmt)
        );
    }

    // --------------------------------------------------
    // Control flow
    // --------------------------------------------------

    private function lowerWhile(WhileStmt $stmt): void
    {
        $startLabel = $this->newLabel('while_start_');
        $endLabel   = $this->newLabel('while_end_');

        // registrar targets
        $this->continueStack[] = $startLabel;
        $this->breakStack[]    = $endLabel;

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($startLabel)])
        );

        $condReg = $this->lowerExpression($stmt->condition);

        $this->program->add(
            new IrInstruction(
                OpCode::JMP_IF_FALSE,
                [$condReg, IrOperand::label($endLabel)]
            )
        );

        foreach ($stmt->body as $s) {
            $this->lowerStatement($s);
        }

        $this->program->add(
            new IrInstruction(OpCode::JMP, [IrOperand::label($startLabel)])
        );

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
        );

        // salir del bucle
        array_pop($this->breakStack);
        array_pop($this->continueStack);
    }

    private function lowerTopLevel(array $statements): void
    {
        $segment = [];

        foreach ($statements as $stmt) {
            if ($stmt instanceof ParallelStmt) {
                // Emitir primero el segmento secuencial acumulado (solo worker 0)
                if (!empty($segment)) {
                    $this->lowerSequentialSegmentOnlyWorker0($segment);
                    $segment = [];
                }

                // Emitir el parallel como constructo estructural
                $this->lowerParallelStmt($stmt);
                continue;
            }

            $segment[] = $stmt;
        }

        // Último segmento secuencial
        if (!empty($segment)) {
            if ($this->hasParallel) {
                $this->lowerSequentialSegmentOnlyWorker0($segment);
            } else {
                foreach ($segment as $s) {
                    $this->lowerStatement($s);
                }
            }
        }
    }

    private function lowerSequentialSegmentOnlyWorker0(array $segment): void
    {
        // if (workerId() != 0) goto skip;
        $rWorkerId = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('workerId'), $rWorkerId]
            )
        );

        $rZero = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::LOAD_CONST,
                [IrOperand::lit(0), $rZero]
            )
        );

        $rCond = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CMP_NE,
                [$rWorkerId, $rZero, $rCond]
            )
        );

        $skipLabel = $this->newLabel('seq_skip_');

        // JMP_IF_FALSE salta si cond == false.
        // Queremos saltar el segmento si cond == true (workerId != 0),
        // así que invertimos: si NO(cond) -> ejecuta; si cond -> goto skip.
        //
        // Forma mínima con lo que tienes:
        // if (cond) goto skip:
        //   JMP_IF_FALSE cond, label_after_jmp
        //   JMP skip
        // label_after_jmp:
        $afterJmpLabel = $this->newLabel('seq_after_if_');

        $this->program->add(
            new IrInstruction(
                OpCode::JMP_IF_FALSE,
                [$rCond, IrOperand::label($afterJmpLabel)]
            )
        );

        $this->program->add(
            new IrInstruction(
                OpCode::JMP,
                [IrOperand::label($skipLabel)]
            )
        );

        $this->program->add(
            new IrInstruction(
                OpCode::LABEL,
                [IrOperand::label($afterJmpLabel)]
            )
        );

        // Emitir segmento (solo ejecutará worker 0)
        foreach ($segment as $s) {
            $this->lowerStatement($s);
        }

        // Label de salida para workers != 0
        $this->program->add(
            new IrInstruction(
                OpCode::LABEL,
                [IrOperand::label($skipLabel)]
            )
        );
    }

    private function lowerParallelStmt(ParallelStmt $stmt): void
    {
        // rW = N
        $rW = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::LOAD_CONST,
                [IrOperand::lit($stmt->workers), $rW]
            )
        );

        // parallelRun(N)
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('parallelRun'), $rW]
            )
        );

        // --- GUARD: si workerId >= N, saltar el cuerpo ---
        $rWorkerId = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('workerId'), $rWorkerId]
            )
        );

        // cond = (workerId < N)
        $rCond = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CMP_LT,
                [$rWorkerId, $rW, $rCond]
            )
        );

        $afterParallelBody = $this->newLabel('parallel_after_body_');

        // if (!cond) goto afterParallelBody;
        $this->program->add(
            new IrInstruction(
                OpCode::JMP_IF_FALSE,
                [$rCond, IrOperand::label($afterParallelBody)]
            )
        );

        // cuerpo del bloque (solo workers < N)
        foreach ($stmt->body as $s) {
            $this->lowerStatement($s);
        }

        // salida del cuerpo paralelo
        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($afterParallelBody)])
        );

        // parallelWait(N)
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('parallelWait'), $rW]
            )
        );
    }

    private function lowerIf(IfStmt $stmt): void
    {
        $condReg = $this->lowerExpression($stmt->condition);

        if ($stmt->elseBlock === null) {
            // IF sin else
            $endLabel = $this->newLabel('if_end_');

            $this->program->add(
                new IrInstruction(
                    OpCode::JMP_IF_FALSE,
                    [$condReg, IrOperand::label($endLabel)]
                )
            );

            foreach ($stmt->thenBlock as $s) {
                $this->lowerStatement($s);
            }

            $this->program->add(
                new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
            );

            return;
        }

        // IF con else
        $elseLabel = $this->newLabel('if_else_');
        $endLabel  = $this->newLabel('if_end_');

        $this->program->add(
            new IrInstruction(
                OpCode::JMP_IF_FALSE,
                [$condReg, IrOperand::label($elseLabel)]
            )
        );

        foreach ($stmt->thenBlock as $s) {
            $this->lowerStatement($s);
        }

        $this->program->add(
            new IrInstruction(OpCode::JMP, [IrOperand::label($endLabel)])
        );

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($elseLabel)])
        );

        foreach ($stmt->elseBlock as $s) {
            $this->lowerStatement($s);
        }

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
        );
    }

    private function lowerFor(ForStmt $stmt): void
    {
        $iterReg = $this->lowerExpression($stmt->iterable);

        $startLabel = $this->newLabel('for_start_');
        $endLabel   = $this->newLabel('for_end_');
        
        $this->continueStack[] = $startLabel;
        $this->breakStack[]    = $endLabel;

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($startLabel)])
        );

        $elemReg = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('dir_next'), $iterReg, $elemReg]
            )
        );

        $condReg = $this->newReg();
        $nullReg = $this->newReg();

        $this->program->add(
            new IrInstruction(OpCode::LOAD_CONST, [IrOperand::lit(null), $nullReg])
        );
        $this->program->add(
            new IrInstruction(OpCode::CMP_NE, [$elemReg, $nullReg, $condReg])
        );
        $this->program->add(
            new IrInstruction(
                OpCode::JMP_IF_FALSE,
                [$condReg, IrOperand::label($endLabel)]
            )
        );

        $this->program->add(
            new IrInstruction(
                OpCode::STORE_VAR,
                [$elemReg, IrOperand::var($stmt->varName)]
            )
        );

        foreach ($stmt->body as $s) {
            $this->lowerStatement($s);
        }

        $this->program->add(
            new IrInstruction(OpCode::JMP, [IrOperand::label($startLabel)])
        );

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
        );

        // 🔴 Y ESTO
        array_pop($this->breakStack);
        array_pop($this->continueStack);

        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('dir_close'), $iterReg]
            )
        );
    }


    // --------------------------------------------------
    // Expressions
    // --------------------------------------------------

    private function lowerExpression($expr): IrReg
    {
        if ($expr instanceof Literal) {
            $dst = $this->newReg();
            $this->program->add(
                new IrInstruction(
                    OpCode::LOAD_CONST,
                    [IrOperand::lit($expr->value), $dst]
                )
            );
            return $dst;
        }

        if ($expr instanceof Identifier) {
            $dst = $this->newReg();
            $this->program->add(
                new IrInstruction(
                    OpCode::LOAD_VAR,
                    [IrOperand::var($expr->name), $dst]
                )
            );
            return $dst;
        }


        if ($expr instanceof BinaryExpr) {
            return $this->lowerBinary($expr);
        }

        if ($expr instanceof UnaryExpr) {
            return $this->lowerUnary($expr);
        }

        if ($expr instanceof CallExpr) {
            $dst = $this->newReg();
            $this->lowerCall($expr, $dst);
            return $dst;
        }

        throw new RuntimeException(
            'Expresión no soportada en lowering: ' . get_class($expr)
        );
    }

    private function lowerBinary(BinaryExpr $expr): IrReg
    {
        $left = $this->lowerExpression($expr->left);
        $right = $this->lowerExpression($expr->right);
        $dst = $this->newReg();

        $op = match ($expr->operator) {
            '+'  => OpCode::ADD,
            '-'  => OpCode::SUB,
            '*'  => OpCode::MUL,
            '/'  => OpCode::DIV,
            '<'  => OpCode::CMP_LT,
            '<=' => OpCode::CMP_LE,
            '%'  => OpCode::MOD,
            '>'  => OpCode::CMP_GT,
            '>=' => OpCode::CMP_GE,
            '==' => OpCode::CMP_EQ,
            '!=' => OpCode::CMP_NE,
            default => throw new RuntimeException(
                "Operador binario no soportado: {$expr->operator}"
            ),
        };

        $this->program->add(
            new IrInstruction($op, [$left, $right, $dst])
        );

        return $dst;
    }

    private function lowerUnary(UnaryExpr $expr): IrReg
    {
        $src = $this->lowerExpression($expr->expr);
        $dst = $this->newReg();

        $op = match ($expr->operator) {
            '-'   => OpCode::NEG,
            'not' => OpCode::NOT,
            default => throw new RuntimeException(
                "Operador unario no soportado: {$expr->operator}"
            ),
        };

        $this->program->add(
            new IrInstruction($op, [$src, $dst])
        );

        return $dst;
    }

    private function lowerCall(CallExpr $call, ?IrReg $dst): void
    {
        $argRegs = [];
        foreach ($call->args as $arg) {
            $argRegs[] = $this->lowerExpression($arg);
        }

        if ($dst !== null) {
            // llamada con retorno
            $this->program->add(
                new IrInstruction(
                    OpCode::CALL_BUILTIN,
                    [
                        IrOperand::lit($call->name),
                        ...$argRegs,
                        $dst
                    ]
                )
            );
        } else {
            // llamada como statement (sin retorno)
            $this->program->add(
                new IrInstruction(
                    OpCode::CALL_BUILTIN,
                    [
                        IrOperand::lit($call->name),
                        ...$argRegs
                    ]
                )
            );
        }
    }

}
