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

require_once SOUL_ROOT . '/ast/Identifier.php';
require_once SOUL_ROOT . '/ast/Literal.php';
require_once SOUL_ROOT . '/ast/CallExpr.php';
require_once SOUL_ROOT . '/ast/BinaryExpr.php';
require_once SOUL_ROOT . '/ast/UnaryExpr.php';
require_once SOUL_ROOT . '/ast/CheckExpr.php';
class Analyzer
{
    private array $scopes = [];
    private array $errors = [];

    public function analyze(Program $program): void
    {
        $this->enterScope();

        // ---------------------------------
        // Constantes internas de fileInfo
        // ---------------------------------
        $this->scopes[count($this->scopes) - 1]['exists']    = 'int';
        $this->scopes[count($this->scopes) - 1]['isFile']    = 'int';
        $this->scopes[count($this->scopes) - 1]['isDir']     = 'int';
        $this->scopes[count($this->scopes) - 1]['isSymlink'] = 'int';
        $this->scopes[count($this->scopes) - 1]['size']      = 'int';
        $this->scopes[count($this->scopes) - 1]['mtime']     = 'int';
        $this->scopes[count($this->scopes) - 1]['ext']       = 'int';
        $this->scopes[count($this->scopes) - 1]['name']      = 'int';

        foreach ($program->statements as $stmt) {
            $this->analyzeStatement($stmt);
        }

        $this->leaveScope();
    }


    public function getErrors(): array
    {
        return $this->errors;
    }

    // -----------------------------
    // Scopes
    // -----------------------------

    private function enterScope(): void
    {
        $this->scopes[] = [];
    }

    private function leaveScope(): void
    {
        array_pop($this->scopes);
    }

    private function declareVar(string $name, string $type): void
    {
        $scope =& $this->scopes[count($this->scopes) - 1];

        if (isset($scope[$name])) {
            $this->error("Variable '$name' redeclarada");
        } else {
            $scope[$name] = $type;
        }
    }

    private function lookupVar(string $name): ?string
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (isset($this->scopes[$i][$name])) {
                return $this->scopes[$i][$name];
            }
        }
        return null;
    }

    // -----------------------------
    // Statements
    // -----------------------------

    private function analyzeStatement($stmt): void
    {
        
        //var_dump(get_class($stmt));
 
        if ($stmt instanceof VarDecl) {
            $this->analyzeVarDecl($stmt);
        } elseif ($stmt instanceof CheckExpr) {
            $this->analyzeCheckExpr($stmt);
        } elseif ($stmt instanceof ParallelStmt) {
            $this->analyzeParallel($stmt);
        } elseif ($stmt instanceof ForStmt) {
            $this->analyzeFor($stmt);
        } elseif ($stmt instanceof CallExpr) {
            $this->analyzeExpression($stmt);
        } elseif ($stmt instanceof StopStmt) {
            // siempre válido
        } elseif ($stmt instanceof Assignment) {
            $this->analyzeAssignment($stmt);      
        } elseif ($stmt instanceof IfStmt) {
            $this->analyzeIf($stmt);          
        } elseif ($stmt instanceof BreakStmt) {
        } elseif ($stmt instanceof ContinueStmt) {                    
        } elseif ($stmt instanceof WhileStmt) {
            $this->analyzeWhile($stmt);        
        } else {
                $this->error("Statement no soportado: " . get_class($stmt));

        }
    }

    private function analyzeWhile(WhileStmt $stmt): void
    {
        $condType = $this->analyzeExpression($stmt->condition);

        if ($condType !== "bool") {
            $this->error("La condición de while debe ser booleana");
        }

        $this->enterScope();
        foreach ($stmt->body as $s) {
            $this->analyzeStatement($s);
        }
        $this->leaveScope();
    }


    private function analyzeAssignment(Assignment $stmt): void
    {
        $exprType = $this->analyzeExpression($stmt->value);

        $varType = $this->lookupVar($stmt->name);

        if ($varType === null) {
            // Declaración implícita en primera asignación
            $this->declareVar($stmt->name, $exprType ?? "any");
            return;
        }

        if (
            $exprType !== $varType &&
            !($varType === "path" && $exprType === "str")
        ) {
            $this->error("Asignación incompatible a '{$stmt->name}'");
        }
    }



    private function analyzeVarDecl(VarDecl $decl): void
    {
        $exprType = $this->analyzeExpression($decl->value);

        if (
            $exprType !== $decl->type &&
            !($decl->type === "path" && $exprType === "str")
        ) {
            $this->error("Asignación incompatible a '{$decl->name}'");
        }

        $this->declareVar($decl->name, $decl->type);
    }


    // -----------------------------
    // Expressions
    // -----------------------------

    private function analyzeExpression($expr): ?string
    {
        if ($expr instanceof Literal) {
            return $expr->type;
        }

        if ($expr instanceof Identifier) {
            $type = $this->lookupVar($expr->name);
            if ($type === null) {
                $this->error("Variable '{$expr->name}' no declarada");
            }
            return $type;
        }

        if ($expr instanceof CallExpr) {
            return $this->analyzeCall($expr);
        }

        if ($expr instanceof BinaryExpr) {
            return $this->analyzeBinary($expr);
        }

        if ($expr instanceof CheckExpr) {
            return $this->analyzeCheckExpr($expr);
        }

        if ($expr instanceof UnaryExpr) {
            return $this->analyzeUnary($expr);
        }


        $this->error("Expresión no soportada");
        return null;
    }

    private function analyzeBinary(BinaryExpr $expr): ?string
    {
        $left = $this->analyzeExpression($expr->left);
        $right = $this->analyzeExpression($expr->right);
        $op = $expr->operator;

        // Comparadores → siempre bool
        if (in_array($op, [">", "<", ">=", "<=", "==", "!="], true)) {
            return "bool";
        }

        // Operadores aritméticos permitidos
        if (in_array($op, ["+", "-", "*", "/", "%"], true)) {

            // Solo ints por ahora (v0.1)
            if ($left !== "int" || $right !== "int") {
                $this->error("El operador '$op' solo puede usarse con enteros");
                return "int";  
            }

            return "int";
        }

        // Soporte especial de concatenación string (+)
        if ($op === "+" && ($left === "str" || $right === "str")) {
            return "str";
        }

        $this->error("Operador binario no soportado: $op");
        return null;
    }


    private function analyzeUnary(UnaryExpr $expr): ?string
    {
        $innerType = $this->analyzeExpression($expr->expr);

        if ($expr->operator === "-") {
            if ($innerType !== "int") {
                $this->error("El operador '-' solo puede aplicarse a enteros");
            }
            return "int";
        }

        if ($expr->operator === "not") {
            if ($innerType !== "bool") {
                $this->error("El operador 'not' solo puede aplicarse a booleanos");
            }
            return "bool";
        }

        $this->error("Operador unario no soportado: {$expr->operator}");
        return null;
    }


    private function analyzeCheckExpr(CheckExpr $expr): ?string
    {
        $condType = $this->analyzeExpression($expr->expr);
        if ($condType !== "bool") {
            $this->error("El operador !! solo puede usarse sobre expresiones booleanas");
        }
        return "bool";
    }

    // -----------------------------
    // Control
    // -----------------------------

    private function analyzeFor(ForStmt $stmt): void
    {
        $iterType = $this->analyzeExpression($stmt->iterable);

        if (($iterType ?? "") !== "diriter") {
            $this->error("for solo puede iterar sobre iteradores de directorio (listdir)");
        }


        $this->enterScope();
        $this->declareVar($stmt->varName, $stmt->varType);

        foreach ($stmt->body as $s) {
            $this->analyzeStatement($s);
        }

        $this->leaveScope();
    }

    private function analyzeIf(IfStmt $stmt): void
    {
        $condType = $this->analyzeExpression($stmt->condition);

        if ($condType !== "bool") {
            $this->error("La condición del if debe ser booleana");
        }

        // then
        $this->enterScope();
        foreach ($stmt->thenBlock as $s) {
            $this->analyzeStatement($s);
        }
        $this->leaveScope();

        // else (si existe)
        if ($stmt->elseBlock !== null) {
            $this->enterScope();
            foreach ($stmt->elseBlock as $s) {
                $this->analyzeStatement($s);
            }
            $this->leaveScope();
        }
    }


    private function analyzeParallel(ParallelStmt $stmt): void
    {
        // parallel NO introduce un nuevo scope léxico
        foreach ($stmt->body as $s) {
            $this->analyzeStatement($s);
        }
    }

    // -----------------------------
    // Builtins
    // -----------------------------

    private function analyzeCall(CallExpr $call): ?string
    {        
        $builtins = [

            // FS core
            "mkdir"    => "bool",
            "copy"     => "bool",
            "move"     => "bool",
            "remove"   => "void",
            "removeTree" => "bool",

            // Stat
            "stat"     => "statinfo",
            "isfile"   => "bool",
            "isdir"    => "bool",
            "mtime"    => "int",            

            // Dir iteration
            "listdir"    => "diriter",
            "dir_next"   => "str",
            "dir_close"  => "void",
            "walk"       => "diriter",
            "walk_next"  => "str",
            "walk_close" => "void",            
            "rmdir"      => "bool",
            "copyFolder" => "bool",
            "backup"     => "bool",

            // Overview
            "overview"            => "overviewinfo",
            "overviewFiles"       => "int",
            "overviewDirs"        => "int",
            "overviewSize"        => "int",
            "overviewExtCount"    => "int",
            "overviewExtName"     => "str",
            "overviewExtFiles"    => "int",
            "overviewExtSize"     => "int",
            "overviewSummary"     => "str",

            // Strings
            "concat"     => "str",
            "join"       => "str",
            "slice"      => "str",
            "length"     => "int",
            "startswith" => "bool",
            "endswith"   => "bool",
            "buildstr"   => "str",
            "json_encode"  => "str",
            "json_pair_str" => "str",
            "json_pair_int" => "str",
            "json_object"   => "str",

            // IO
            "open"   => "int",
            "write"  => "void",
            "close"  => "void",
            "append" => "bool",

            // Conversion / Print
            "toStr"    => "str",
            "print"    => "void",
            "printStr" => "void",
            "toInt"    => "int",            

            // CLI
            "argc"  => "int",
            "argv"  => "str",
            'arg'   => 'str',

            // Networking
            "http_get"  => "netconn",
            "net_read"  => "str",
            "net_close" => "void",

            // Parallelism
            "workerId"    => "int",
            "workerCount" => "int",

            // Atomics
            "atomicCounter" => "int",
            "atomicAppend"  => "bool",
        ];


        if (!isset($builtins[$call->name])) {
            $this->error("Función '{$call->name}' no existe");
            return null;
        }

        foreach ($call->args as $arg) {
            $this->analyzeExpression($arg);
        }

        // --- Validación argc/argv/arg ---
        if ($call->name === 'argc') {
            if (count($call->args) !== 0) {
                $this->error("argc no acepta argumentos");
            }
            return "int";
        }

        if ($call->name === 'argv') {
            if (count($call->args) !== 1) {
                $this->error("argv expects 1 argument");
                return "str";
            }
            $t0 = $this->analyzeExpression($call->args[0]);
            if ($t0 !== "int") {
                $this->error("argv: el índice debe ser int");
            }
            return "str";
        }

        if ($call->name === 'arg') {
            // arg(flag) o arg(flag, default)
            $n = count($call->args);
            if ($n !== 1 && $n !== 2) {
                $this->error("arg expects 1 or 2 arguments");
                return "str";
            }

            $t0 = $this->analyzeExpression($call->args[0]);
            if ($t0 !== "str") {
                $this->error("arg: el primer argumento (flag) debe ser str");
            }

            if ($n === 2) {
                $t1 = $this->analyzeExpression($call->args[1]);
                if ($t1 !== "str") {
                    $this->error("arg: el segundo argumento (default) debe ser str");
                }
            }

            return "str";
        }



        // --- Validación buildstr ---
        if ($call->name === 'buildstr') {

            if (count($call->args) < 1) {
                $this->error("buildstr expects at least 1 argument");
                return "str";
            }

            foreach ($call->args as $arg) {
                $argType = $this->analyzeExpression($arg);
                if (!in_array($argType, ['str', 'int'], true)) {
                    $this->error("buildstr: invalid argument type ($argType)");
                }
            }

            return "str";
        }

        return $builtins[$call->name];
    }

    // -----------------------------
    // Errors
    // -----------------------------

    private function error(string $msg): void
    {
        $this->errors[] = $msg;
    }
}
