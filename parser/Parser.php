<?php

require_once SOUL_ROOT . '/lexer/Token.php';

// AST
require_once SOUL_ROOT . '/ast/Program.php';
require_once SOUL_ROOT . '/ast/VarDecl.php';
require_once SOUL_ROOT . '/ast/Assignment.php';
require_once SOUL_ROOT . '/ast/IfStmt.php';
require_once SOUL_ROOT . '/ast/ForStmt.php';
require_once SOUL_ROOT . '/ast/ParallelStmt.php';
require_once SOUL_ROOT . '/ast/StopStmt.php';
require_once SOUL_ROOT . '/ast/WhileStmt.php';
require_once SOUL_ROOT . '/ast/BreakStmt.php';
require_once SOUL_ROOT . '/ast/ContinueStmt.php';
require_once SOUL_ROOT . '/ast/ReturnStmt.php';

require_once SOUL_ROOT . '/ast/Identifier.php';
require_once SOUL_ROOT . '/ast/Literal.php';
require_once SOUL_ROOT . '/ast/CallExpr.php';
require_once SOUL_ROOT . '/ast/BinaryExpr.php';
require_once SOUL_ROOT . '/ast/UnaryExpr.php';
require_once SOUL_ROOT . '/ast/CheckExpr.php';


class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;
    private array $errors = [];

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function parseProgram(): Program
    {
        $statements = [];
        $this->skipNewlines();

        while (!$this->check("EOF")) {
            $stmt = $this->parseStatement();

            if ($stmt === null) {
                $this->skipNewlines();
                continue;
            }

            $statements[] = $stmt;
            $this->skipNewlines();
        }

        return new Program($statements);
    }

    // -----------------------------
    // Statements
    // -----------------------------

    private function parseStatement()
    {

        // Ignorar tokens estructurales
        if ($this->match("NEWLINE", "DEDENT")) {
            return null;
        }

        // Un INDENT al comienzo de una sentencia no abre ningún bloque
        // (los bloques consumen su INDENT en parseBlock).
        if ($this->check("INDENT")) {
            $this->error("Indentación inesperada");
            $this->advance();
            return null;
        }
        // Declaración de variable: TYPE IDENTIFIER '=' expr
        if ($this->match("TYPE")) {
            return $this->parseVarDeclFromTypeToken($this->previous());
        }


        if ($this->match("CONTINUE")) {
            $this->endOfStatement();
            return new ContinueStmt();
        }

        if ($this->match("RETURN")) {
            if (!$this->check("NEWLINE") && !$this->check("DEDENT") && !$this->check("EOF")) {
                $this->error("'return' no admite valor en v0.1");
                $this->synchronize();
                return new ReturnStmt();
            }
            $this->endOfStatement();
            return new ReturnStmt();
        }

        if ($this->match("IF")) {
            return $this->parseIf();
        }

        if ($this->match("PARALLEL")) {
            return $this->parseParallel();
        }

        if ($this->match("FOR")) {
            return $this->parseFor();
        }

        if ($this->match("WHILE")) {
            return $this->parseWhile();
        }        

        if ($this->match("STOP")) {
            return $this->parseStopAfterStopKeyword();
        }

        if ($this->match("BREAK")) {
            $this->endOfStatement();
            return new BreakStmt();
        }

        // `tipoDesconocido nombre = expr`: sin esta comprobación el tipo se
        // descartaba y `nombre = expr` se tomaba como asignación implícita.
        if ($this->check("IDENTIFIER") && $this->checkAt(1, "IDENTIFIER") && $this->checkAt(2, "EQUALS")) {
            $this->error("Tipo desconocido '{$this->peek()->value}' (tipos: int, bool, str, statinfo, diriter, overviewinfo, analyzeinfo, netconn)");
            $this->synchronize();
            return null;
        }

        // Asignación: IDENTIFIER '=' expr
        if ($this->check("IDENTIFIER") && $this->checkNext("EQUALS")) {
            $nameTok = $this->advance();
            $this->consume("EQUALS", "Se esperaba '=' en asignación");
            $value = $this->parseExpression();
            $this->endOfStatement();
            return new Assignment($nameTok->value, $value);
        }


        // ExprStmt / CheckExprStmt
        $stmtLine = $this->peek()->line ?? 0;
        $errorsBefore = count($this->errors);
        $expr = $this->parseExpression();

        // expr !! stop "msg"
        if ($this->match("CHECK")) {
            $this->consume("STOP", "Se esperaba 'stop' después de '!!'");
            $msg = null;

            if (!$this->check("NEWLINE") && !$this->check("DEDENT") && !$this->check("EOF")) {
                $msg = $this->parseExpression();
            }

            $this->endOfStatement();
            return new CheckExpr($expr, new StopStmt($msg));
        }

        // statement expresión: SOLO llamadas
        $this->endOfStatement();

        if ($expr instanceof CallExpr) {
            return $expr;
        }

        // Cualquier otra expresión no es una sentencia. Antes se descartaba en
        // silencio (así se perdía `return` y cualquier typo como `x + 1`).
        // Si parseExpression ya informó de un error, no lo duplicamos.
        if (count($this->errors) === $errorsBefore) {
            $this->errorAt($stmtLine, "Una expresión sin efecto no es una sentencia válida (solo se permiten llamadas)");
        }
        return null;
    }

    private function parseWhile(): WhileStmt
    {
        $condition = $this->parseExpression();
        $this->consume("COLON", "Se esperaba ':' después de condición en while");
        $this->consume("NEWLINE", "Se esperaba fin de línea después de ':' en while");

        $body = $this->parseBlock();

        return new WhileStmt($condition, $body);
    }


    private function parseVarDeclFromTypeToken(Token $typeTok): VarDecl
    {
        $type = $typeTok->value;

        // Soporte de arrays: TYPE(path) '[' ']'
        if ($this->match("LBRACKET")) {
            $this->consume("RBRACKET", "Se esperaba ']' para tipo array");
            $type = $type . "[]";
        }

        $nameTok = $this->consume("IDENTIFIER", "Se esperaba nombre de variable tras el tipo");
        $this->consume("EQUALS", "Se esperaba '=' en declaración de variable");
        $value = $this->parseExpression();
        $this->endOfStatement();
        return new VarDecl($type, $nameTok->value, $value);

    }

    private function parseStopAfterStopKeyword(): StopStmt
    {
        $msg = null;

        if (
            !$this->check("NEWLINE") &&
            !$this->check("DEDENT") &&
            !$this->check("EOF")
        ) {
            $msg = $this->parseExpression();
        }

        $this->endOfStatement();
        return new StopStmt($msg);
    }


    private function parseIf(): IfStmt
    {
        $condition = $this->parseExpression();
        $this->consume("COLON", "Se esperaba ':' después de condición en if");
        $this->consume("NEWLINE", "Se esperaba fin de línea después de ':' en if");
        $thenBlock = $this->parseBlock();

        $elseBlock = null;
        $this->skipNewlines();

        if ($this->match("ELSE")) {
            $this->consume("COLON", "Se esperaba ':' después de else");
            $this->consume("NEWLINE", "Se esperaba fin de línea después de ':' en else");
            $elseBlock = $this->parseBlock();
        }

        return new IfStmt($condition, $thenBlock, $elseBlock);
    }

    private function parseFor(): ForStmt
    {
        // for [TYPE] IDENTIFIER in <expr> : NEWLINE block

        $varType = "path"; // ← tipo por defecto en Soul v0.1

        // Tipo opcional
        if ($this->check("TYPE")) {
            $typeTok = $this->advance();
            $varType = $typeTok->value;

            if ($this->match("LBRACKET")) {
                $this->consume("RBRACKET", "Se esperaba ']' para tipo array");
                $varType .= "[]";
            }
        }

        $nameTok = $this->consume("IDENTIFIER", "Se esperaba nombre de variable en 'for'");
        $this->consume("IN", "Se esperaba 'in' en 'for'");
        $iterable = $this->parseExpression();
        $this->consume("COLON", "Se esperaba ':' en 'for'");
        $this->consume("NEWLINE", "Se esperaba fin de línea tras ':' en 'for'");
        $body = $this->parseBlock();

        return new ForStmt($varType, $nameTok->value, $iterable, $body);
    }


    private function parseParallel(): ParallelStmt
    {
        // parallel <INT> : NEWLINE block
        $workersTok = $this->consume("INT", "Se esperaba número de workers en 'parallel'");
        $this->consume("COLON", "Se esperaba ':' después de parallel");
        $this->consume("NEWLINE", "Se esperaba fin de línea tras ':' en parallel");
        $body = $this->parseBlock();

        return new ParallelStmt((int)$workersTok->value, $body);
    }

    private function parseBlock(): array
    {
        $this->skipNewlines();   
        $this->consume("INDENT", "Se esperaba INDENT para iniciar bloque");

        $statements = [];
        $this->skipNewlines();

        while (!$this->check("DEDENT") && !$this->check("EOF")) {
            $stmt = $this->parseStatement();

            if ($stmt === null) {
                $this->skipNewlines();
                continue;
            }

            $statements[] = $stmt;
            $this->skipNewlines();
        }

        $this->consume("DEDENT", "Se esperaba DEDENT para cerrar bloque");
        return $statements;
    }

    // -----------------------------
    // Expressions (precedencia)
    // -----------------------------

    private function parseExpression()
    {
        return $this->parseComparison();
    }

    private function parseComparison()
    {
        $expr = $this->parseTerm();

        while ($this->match("GT", "GE", "LT", "LE", "EQ", "NEQ")) {
            $op = $this->previous();
            $right = $this->parseTerm();
            $expr = new BinaryExpr($expr, $this->mapOperator($op), $right);
        }

        return $expr;
    }

    private function parseTerm()
    {
        $expr = $this->parseFactor();

        while ($this->match("PLUS", "MINUS")) {
            $op = $this->previous();
            $right = $this->parseFactor();
            $expr = new BinaryExpr($expr, $this->mapOperator($op), $right);
        }

        return $expr;
    }

    private function parseFactor()
    {
        $expr = $this->parseUnary();

        while ($this->match("STAR", "SLASH", "MOD")) {
            $op = $this->previous();
            $right = $this->parseUnary();
            $expr = new BinaryExpr($expr, $this->mapOperator($op), $right);
        }

        return $expr;
    }

    private function parseUnary()
    {
        if ($this->match("NOT")) {
            $op = $this->previous();
            $right = $this->parseUnary();
            return new UnaryExpr($op->value, $right);
        }

        if ($this->match("MINUS")) {
            $op = $this->previous();
            $right = $this->parseUnary();
            return new UnaryExpr("-", $right);
        }

        return $this->parseCall();
    }

    private function parseCall()
    {
        $expr = $this->parsePrimary();

        while ($this->match("LPAREN")) {
            // llamada: expr debe ser Identifier (por simplicidad v0.1)
            $args = [];
            if (!$this->check("RPAREN")) {
                do {
                    $args[] = $this->parseExpression();
                } while ($this->match("COMMA"));
            }
            $this->consume("RPAREN", "Se esperaba ')' al cerrar llamada");

            if ($expr instanceof Identifier) {
                $expr = new CallExpr($expr->name, $args);
            } else {
                $this->error("Solo se permiten llamadas a identificadores en v0.1");
            }
        }

        return $expr;
    }

    private function parsePrimary()
    {
        if ($this->match("INT")) {
            return new Literal($this->previous()->value, "int");
        }

        if ($this->match("TYPE")) {
            // Permitir usar tipos como identificadores en expresiones (ej: path, str)
            return new Identifier($this->previous()->value);
        }

        if ($this->match("NULL")) {
            return new Literal(null, "null");
        }        

        if ($this->match("BOOL")) {
            return new Literal((bool)$this->previous()->value, "bool");
        }

        if ($this->match("STRING")) {
            // En v0.1, las strings literales son "str" (no distinguimos path automáticamente aquí)
            return new Literal($this->previous()->value, "str");
        }

        if ($this->match("IDENTIFIER")) {
            return new Identifier($this->previous()->value);
        }

        if ($this->match("LPAREN")) {
            $expr = $this->parseExpression();
            $this->consume("RPAREN", "Se esperaba ')'");
            return $expr;
        }

        $this->error("Expresión inesperada: " . $this->peek()->type);
        $this->advance(); // ← CRÍTICO: evita bucle infinito
        return new Literal(null, "void");

    }

    private function mapOperator(Token $op): string
    {
        return match ($op->type) {
            "PLUS" => "+",
            "MINUS" => "-",
            "SLASH" => "/",
            "STAR" => "*",
            "MOD" => "%",
            "GT" => ">",
            "GE" => ">=",
            "LT" => "<",
            "LE" => "<=",
            "EQ" => "==",
            "NEQ" => "!=",
            default => throw new RuntimeException("Operador no soportado: {$op->type}"),
        };
    }


    // -----------------------------
    // Helpers
    // -----------------------------

    private function skipNewlines(): void
    {
        while ($this->match("NEWLINE")) { /* skip */ }
    }

    private function synchronize(): void
    {
        // Salta tokens hasta un punto razonable (NEWLINE o DEDENT o EOF)
        while (!$this->check("NEWLINE") && !$this->check("DEDENT") && !$this->check("EOF")) {
            $this->advance();
        }
        $this->match("NEWLINE");
    }

    private function error(string $message): void
    {
        $tok = $this->peek();
        $line = $tok->line ?? 0;
        $this->errors[] = "Línea {$line}: {$message}";
    }

    private function errorAt(int $line, string $message): void
    {
        $this->errors[] = "Línea {$line}: {$message}";
    }

    /**
     * Una sentencia simple debe terminar en fin de línea (o justo antes de un
     * DEDENT/EOF). Si sobran tokens ("int x = 1 2") es un error, no algo que
     * se pueda reinterpretar como otra sentencia.
     */
    private function endOfStatement(): void
    {
        if ($this->match("NEWLINE")) return;
        if ($this->check("DEDENT") || $this->check("EOF")) return;

        $this->error("Se esperaba fin de línea tras la sentencia (se encontró {$this->peek()->type})");
        $this->synchronize();
    }

    private function consume(string $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        $this->error($message . " (se encontró {$this->peek()->type})");

        // AVANZAR SIEMPRE para evitar bucle infinito
        if (!$this->isAtEnd()) {
            $this->advance();
        }

        return new Token($type, null, $this->previous()->line ?? 0);
    }


    private function match(string ...$types): bool
    {
        foreach ($types as $t) {
            if ($this->check($t)) {
                $this->advance();
                return true;
            }
        }
        return false;
    }

    private function check(string $type): bool
    {
        return $this->peek()->type === $type;
    }


    private function checkAt(int $offset, string $type): bool
    {
        $idx = $this->pos + $offset;
        if ($idx >= count($this->tokens)) return false;
        return $this->tokens[$idx]->type === $type;
    }

    private function checkNext(string $type): bool
    {
        if ($this->pos + 1 >= count($this->tokens)) return false;
        return $this->tokens[$this->pos + 1]->type === $type;
    }

    private function advance(): Token
    {
        if (!$this->isAtEnd()) $this->pos++;
        return $this->previous();
    }

    private function isAtEnd(): bool
    {
        return $this->peek()->type === "EOF";
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->pos - 1];
    }
}
