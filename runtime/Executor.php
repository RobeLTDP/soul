<?php

require_once SOUL_ROOT . '/lexer/Token.php';
require_once SOUL_ROOT . '/runtime/Progress.php';

// AST
require_once SOUL_ROOT . '/ast/Program.php';
require_once SOUL_ROOT . '/ast/VarDecl.php';
require_once SOUL_ROOT . '/ast/Assignment.php';
require_once SOUL_ROOT . '/ast/IfStmt.php';
require_once SOUL_ROOT . '/ast/ForStmt.php';
require_once SOUL_ROOT . '/ast/ParallelStmt.php';
require_once SOUL_ROOT . '/ast/StopStmt.php';
require_once SOUL_ROOT . '/ast/WhileStmt.php';
require_once SOUL_ROOT . '/ast/Identifier.php';
require_once SOUL_ROOT . '/ast/Literal.php';
require_once SOUL_ROOT . '/ast/CallExpr.php';
require_once SOUL_ROOT . '/ast/BinaryExpr.php';
require_once SOUL_ROOT . '/ast/UnaryExpr.php';
require_once SOUL_ROOT . '/ast/CheckExpr.php';

class Executor
{
    private array $env = [];
    private array $builtins = [];
    private Progress $progress;
    private float $lastGlobalTick = 0;
    private int $globalTickIntervalMs = 500;

    public function __construct()
    {
        $this->progress = new Progress();

        $this->builtins = [
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

                $this->progress->begin("copy");

                try {
                    if (is_dir($dst)) {
                        $dst = rtrim($dst, DIRECTORY_SEPARATOR)
                            . DIRECTORY_SEPARATOR
                            . basename($src);
                    }

                    $ok = copy($src, $dst);

                    $this->progress->end($ok);
                    return $ok;

                } catch (\Throwable $e) {
                    $this->progress->end(false, $e->getMessage());
                    throw $e;
                }
            },
            "mkdir" => function ($args) {
                $path = $args[0];

                $this->progress->begin("mkdir");

                try {
                    if (!is_dir($path)) {
                        mkdir($path, 0777, true);
                    }

                    $this->progress->end(true);
                    return true;

                } catch (\Throwable $e) {
                    $this->progress->end(false, $e->getMessage());
                    throw $e;
                }
            },
            "write" => function ($args) {
                [$path, $text] = $args;

                $this->progress->begin("write");

                try {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }

                    $ok = file_put_contents($path, $text, FILE_APPEND) !== false;

                    $this->progress->end($ok);
                    return $ok;

                } catch (\Throwable $e) {
                    $this->progress->end(false, $e->getMessage());
                    throw $e;
                }
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
            "endswith" => function ($args) {
                [$text, $suffix] = $args;

                if ($suffix === "") {
                    return true;
                }

                if (strlen($suffix) > strlen($text)) {
                    return false;
                }

                return substr($text, -strlen($suffix)) === $suffix;
            },
            "startswith" => function ($args) {
                [$text, $prefix] = $args;

                if ($prefix === "") {
                    return true;
                }

                if (strlen($prefix) > strlen($text)) {
                    return false;
                }

                return substr($text, 0, strlen($prefix)) === $prefix;
            },

            "length" => fn($args) => strlen($args[0]),


        ];
    }

    public function run(Program $program): void
    {
        foreach ($program->statements as $stmt) {
            $this->executeStatement($stmt);
            $this->tickProgress();
        }
    }

    private function tickProgress(): void
    {
        $now = microtime(true);

        if (($now - $this->lastGlobalTick) * 1000 < $this->globalTickIntervalMs) {
            return;
        }

        $this->lastGlobalTick = $now;
        $this->progress->tick();
    }


    private function executeStatement($stmt): void
    {
        if ($stmt instanceof VarDecl) {
            $this->env[$stmt->name] = $this->evaluate($stmt->value);
            return;
        }

        if ($stmt instanceof Assignment) {
            $this->env[$stmt->name] = $this->evaluate($stmt->value);
            return;
        }

        if ($stmt instanceof CallExpr) {
            $this->evaluateCall($stmt);
            return;
        }

        if ($stmt instanceof CheckExpr) {
            $result = $this->evaluate($stmt->expr);
            if (!$result) {
                $this->executeStatement($stmt->onFail);
            }
            return;
        }

        if ($stmt instanceof IfStmt) {
            $this->executeIf($stmt);
            return;
        }

        if ($stmt instanceof StopStmt) {
            $msg = $stmt->message
                ? $this->evaluate($stmt->message)
                : "Ejecución detenida";
            throw new RuntimeException($msg);
        }

        if ($stmt instanceof ForStmt) {
            $this->executeFor($stmt);
            return;
        }

        if ($stmt instanceof WhileStmt) {
            $this->executeWhile($stmt);
            return;
        }
        
        if ($stmt instanceof ParallelStmt) {
            $this->executeParallel($stmt);
            return;
        }

        throw new RuntimeException("Statement no ejecutable");
    }

    private function evaluate($expr)
    {
        if ($expr instanceof Literal) {
            return $expr->value;
        }

        if ($expr instanceof Identifier) {
            if (!array_key_exists($expr->name, $this->env)) {
                throw new RuntimeException("Variable '{$expr->name}' no definida en runtime");
            }
            return $this->env[$expr->name];
        }

        if ($expr instanceof BinaryExpr) {
            $left = $this->evaluate($expr->left);
            $right = $this->evaluate($expr->right);

            return match ($expr->operator) {
                "+" => (is_string($left) || is_string($right))
                        ? $left . $right
                        : $left + $right,
                "-"  => $left - $right,
                "*"  => $left * $right,
                "/" => $right == 0
                        ? throw new RuntimeException("División por cero")
                        : $left / $right,
                ">"  => $left > $right,
                "<"  => $left < $right,
                ">=" => $left >= $right,
                "<=" => $left <= $right,
                "==" => $left == $right,
                "!=" => $left != $right,
                default => throw new RuntimeException("Operador no soportado: {$expr->operator}")
            };
        }

        if ($expr instanceof CallExpr) {
            return $this->evaluateCall($expr);
        }

        if ($expr instanceof UnaryExpr) {
            $value = $this->evaluate($expr->expr);
            return match ($expr->operator) {
                "-" => -$value,
                "not" => !$value,
                default => throw new RuntimeException("Operador unario no soportado"),
            };
        }

        throw new RuntimeException("Expresión no evaluable");
    }

    private function evaluateCall(CallExpr $call)
    {
        if (!isset($this->builtins[$call->name])) {
            throw new RuntimeException("Función '{$call->name}' no implementada");
        }

        $args = [];
        foreach ($call->args as $arg) {
            $args[] = $this->evaluate($arg);
        }

        return ($this->builtins[$call->name])($args);

        //var_dump(getcwd());

        /*
            return match ($call->name) {

                "print" => (function () use ($args) {
                    foreach ($args as $a) {
                        echo $a;
                    }
                    echo PHP_EOL;
                    return null;
                })(),

                "exists" => file_exists($args[0]),

                "mkdir" => (function () use ($args) {
                    if (!is_dir($args[0])) {
                        mkdir($args[0], 0777, true);
                    }
                    return true;
                })(),

                "copy" => copy($args[0], $args[1]),

                "listdir" => array_values(
                    array_filter(
                        array_map(
                            fn($f) => rtrim($args[0], "/") . "/" . $f,
                            scandir($args[0])
                        ),
                        fn($f) => !str_ends_with($f, "/.") && !str_ends_with($f, "/..")
                    )
                ),

                "len" => count($args[0]),

                "basename" => basename($args[0]),

                default => throw new RuntimeException("Función '{$call->name}' no implementada en runtime"),
            };
        */
    }

    private function executeFor(ForStmt $stmt): void
    {
        $iterable = $this->evaluate($stmt->iterable);

        foreach ($iterable as $value) {
            $this->env[$stmt->varName] = $value;

            foreach ($stmt->body as $s) {
                $this->executeStatement($s);
                $this->tickProgress();
            }
        }
    }

    private function executeWhile(WhileStmt $stmt): void
    {
        while (true) {
            $cond = $this->evaluate($stmt->condition);

            if (!$cond) {
                break;
            }

            foreach ($stmt->body as $s) {
                $this->executeStatement($s);
                $this->tickProgress();
            }
        }
    }


    private function executeParallel(ParallelStmt $stmt): void
    {
        $queue = $stmt->body;
        $workers = $stmt->workers;

        while (!empty($queue)) {
            $batch = array_splice($queue, 0, $workers);
            foreach ($batch as $s) {
                $this->executeStatement($s);
                $this->tickProgress();
            }
        }
    }

    private function normalizePath(string $path): string
    {
        return realpath($path) ?: $path;
    }

    private function executeIf(IfStmt $stmt): void
    {
        $cond = $this->evaluate($stmt->condition);

        if ($cond) {
            foreach ($stmt->thenBlock as $s) {
                $this->executeStatement($s);
            }
        } elseif ($stmt->elseBlock !== null) {
            foreach ($stmt->elseBlock as $s) {
                $this->executeStatement($s);
            }
        }
    }



}
