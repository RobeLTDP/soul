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


final class Lowerer
{
    private IrProgram $program;
    private int $regCounter = 0;
    private int $labelCounter = 0;

    /** @var string[] */
    private array $breakStack = [];
    /** @var string[] */
    private array $continueStack = [];



    public function lower(Program $ast): IrProgram
    {
        $this->program = new IrProgram();
        $this->regCounter = 0;
        $this->labelCounter = 0;

        foreach ($ast->statements as $stmt) {
            $this->lowerStatement($stmt);
        }

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


        if ($stmt instanceof CallExpr) {
            // llamada como statement (sin valor de retorno)
            $this->lowerCall($stmt, null);
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



    private function lowerIf(IfStmt $stmt): void
    {
        $elseLabel = $this->newLabel('if_else_');
        $endLabel  = $this->newLabel('if_end_');

        $condReg = $this->lowerExpression($stmt->condition);

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

        if ($stmt->elseBlock !== null) {
            foreach ($stmt->elseBlock as $s) {
                $this->lowerStatement($s);
            }
        }

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
        );
    }

    private function lowerFor(ForStmt $stmt): void
    {
        // 1) iter = listdir(<expr>)
        $iterReg = $this->newReg();
        // listdir devuelve un "diriter" (handle)
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('listdir'), $this->lowerExpression($stmt->iterable), $iterReg]
            )
        );

        $startLabel = $this->newLabel('for_start_');
        $endLabel   = $this->newLabel('for_end_');

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($startLabel)])
        );

        // 2) elem = dir_next(iter)
        $elemReg = $this->newReg();
        $this->program->add(
            new IrInstruction(
                OpCode::CALL_BUILTIN,
                [IrOperand::lit('dir_next'), $iterReg, $elemReg]
            )
        );

        // 3) if elem == null -> end
        // Usamos CMP_NE + JMP_IF_FALSE para saltar cuando sea null:
        // cond = (elem != null)
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

        // 4) f = elem
        $this->program->add(
            new IrInstruction(
                OpCode::STORE_VAR,
                [$elemReg, IrOperand::var($stmt->varName)]
            )
        );

        // 5) body
        foreach ($stmt->body as $s) {
            $this->lowerStatement($s);
        }

        // 6) loop
        $this->program->add(
            new IrInstruction(OpCode::JMP, [IrOperand::label($startLabel)])
        );

        $this->program->add(
            new IrInstruction(OpCode::LABEL, [IrOperand::label($endLabel)])
        );

        // 7) dir_close(iter)
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

        $operands = [
            IrOperand::lit($call->name),
            ...$argRegs
        ];

        if ($dst !== null) {
            $operands[] = $dst;
        }

        $this->program->add(
            new IrInstruction(OpCode::CALL_BUILTIN, $operands)
        );
    }
}
