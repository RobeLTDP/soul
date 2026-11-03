<?php

require_once SOUL_ROOT . '/lexer/Token.php';

class Lexer
{
    private string $source;
    private int $pos = 0;
    private int $line = 1;
    private bool $atLineStart = true;
    private bool $inBlockComment = false;
    private bool $insideString = false;


    private array $tokens = [];

    /** @var string[] Errores léxicos con formato "Línea N: mensaje" */
    private array $errors = [];
    private int $blockCommentStartLine = 0;

    private array $indentStack = [0];

    public function __construct(string $source)
    {
        // Normalizar finales de línea (CRLF / CR -> LF)
        $source = str_replace("\r\n", "\n", $source);
        $source = str_replace("\r", "\n", $source);

        $this->source = $source;
    }


    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    private function error(string $message, ?int $line = null): void
    {
        $this->errors[] = "Línea " . ($line ?? $this->line) . ": " . $message;
    }

    private function describeChar(string $c): string
    {
        $o = ord($c);
        return ($o >= 32 && $o < 127) ? "'" . $c . "'" : sprintf("0x%02X", $o);
    }

    public function tokenize(): array
    {
        while (!$this->isAtEnd()) {
            $this->scanToken();
        }

        if ($this->inBlockComment) {
            $this->error("Comentario de bloque '##' sin cerrar", $this->blockCommentStartLine);
        }

        // Cerrar indentaciones pendientes
        while (count($this->indentStack) > 1) {
            array_pop($this->indentStack);
            $this->tokens[] = new Token("DEDENT", null, $this->line);
        }

        

        $this->tokens[] = new Token("EOF", null, $this->line);
        return $this->tokens;
    }

    // ---------------------------------


    private function scanToken(): void
    {
        if ($this->isAtEnd()) return;

    // =========================================================
    // NO ANALIZAR COMENTARIOS SI ESTAMOS EN UN STRING
    // =========================================================

    if (!$this->insideString) {

        // Comentario de bloque ## activo
        if ($this->inBlockComment) {
            if ($this->peek() === '#' && $this->peekNext() === '#') {
                $this->advance();
                $this->advance();
                $this->inBlockComment = false;
                return;
            }
            if ($this->peek() === "\n") {
                $this->advance();
                $this->line++;
                $this->atLineStart = true;
                return;
            }
            $this->advance();
            return;
        }

        // Inicio de comentario de bloque ##
        if ($this->peek() === '#' && $this->peekNext() === '#') {
            $this->advance();
            $this->advance();
            $this->inBlockComment = true;
            $this->blockCommentStartLine = $this->line;
            return;
        }
    }


        // ===============================
        // INICIO DE LÍNEA (INDENT + COMENTARIOS)
        // ===============================
        if ($this->atLineStart) {

            $len = strlen($this->source);

            // 1. Medir indentación SIN consumir. Los tabuladores no son
            //    indentación válida: se avisa una vez por línea y se cuentan
            //    como un espacio para no encadenar errores.
            $i = $this->pos;
            $spaces = 0;
            $tabReported = false;
            while ($i < $len && ($this->source[$i] === ' ' || $this->source[$i] === "\t")) {
                if ($this->source[$i] === "\t" && !$tabReported) {
                    $this->error("Tabulador en la indentación; usa espacios");
                    $tabReported = true;
                }
                $spaces++;
                $i++;
            }

            // 2. Solo espacios hasta el final del fichero: nada que indentar
            if ($i >= $len) {
                $this->pos = $len;
                return;
            }

            // 3. Línea vacía (solo espacios + \n)
            if ($this->source[$i] === "\n") {
                $this->pos = $i + 1;
                $this->tokens[] = new Token("NEWLINE", null, $this->line);
                $this->line++;
                $this->atLineStart = true;
                return;
            }

            // 4. Comentarios. No alteran la indentación (igual que en Python).
            if ($this->source[$i] === '#') {

                // '##' indentado: abre un comentario de bloque
                if ($i + 1 < $len && $this->source[$i + 1] === '#') {
                    $this->pos = $i;
                    return;
                }

                $this->pos = $i;
                while (!$this->isAtEnd() && $this->peek() !== "\n") {
                    $this->advance();
                }

                if (!$this->isAtEnd() && $this->peek() === "\n") {
                    $this->advance();
                    $this->tokens[] = new Token("NEWLINE", null, $this->line);
                    $this->line++;
                }

                $this->atLineStart = true;
                return;
            }

            // 5. Calcular INDENT / DEDENT
            $currentIndent = $spaces;
            $lastIndent = end($this->indentStack);

            if ($currentIndent > $lastIndent) {
                $this->indentStack[] = $currentIndent;
                $this->tokens[] = new Token("INDENT", null, $this->line);
            } elseif ($currentIndent < $lastIndent) {
                while ($currentIndent < end($this->indentStack)) {
                    array_pop($this->indentStack);
                    $this->tokens[] = new Token("DEDENT", null, $this->line);
                }

                // El nivel al que volvemos debe haber existido antes
                if ($currentIndent !== end($this->indentStack)) {
                    $this->error("Indentación inconsistente: no coincide con ningún nivel anterior");
                    $this->indentStack[count($this->indentStack) - 1] = $currentIndent;
                }
            }

            // 6. Consumir espacios reales
            $this->pos = $i;
            $this->atLineStart = false;
        }

        // ===============================
        // TOKEN NORMAL
        // ===============================
        if ($this->isAtEnd()) return;

        $c = $this->advance();

        if ($c === ' ' || $c === "\t") return;

        // Comentario de línea tras código: "x = 1  # nota"
        if ($c === '#') {
            while (!$this->isAtEnd() && $this->peek() !== "\n") {
                $this->advance();
            }
            return;
        }

        if ($c === "\n") {
            $this->tokens[] = new Token("NEWLINE", null, $this->line);
            $this->line++;
            $this->atLineStart = true;
            return;
        }

        if ($c === '"') { $this->string(); return; }
        if (ctype_digit($c)) { $this->number($c); return; }
        if (ctype_alpha($c) || $c === '_') { $this->identifier($c); return; }

        if ($c === '!' && $this->match('!')) {
            $this->tokens[] = new Token("CHECK", "!!", $this->line);
            return;
        }
        
        
        if ($c === '>') { $this->tokens[] = new Token($this->match('=') ? "GE" : "GT", null, $this->line); return; }
        if ($c === '<') { $this->tokens[] = new Token($this->match('=') ? "LE" : "LT", null, $this->line); return; }
        if ($c === '=' && $this->match('=')) { $this->tokens[] = new Token("EQ", null, $this->line); return; }
        if ($c === '!' && $this->match('=')) { $this->tokens[] = new Token("NEQ", null, $this->line); return; }

        if ($c === '=') { $this->tokens[] = new Token("EQUALS", null, $this->line); return; }
        if ($c === '+') { $this->tokens[] = new Token("PLUS", null, $this->line); return; }
        if ($c === '-') { $this->tokens[] = new Token("MINUS", null, $this->line); return; }
        if ($c === '*') { $this->tokens[] = new Token("STAR", null, $this->line); return; }
        if ($c === '/') { $this->tokens[] = new Token("SLASH", null, $this->line); return; }
        if ($c === '%') { $this->tokens[] = new Token("MOD", null, $this->line); return; }

        if ($c === '(') { $this->tokens[] = new Token("LPAREN", null, $this->line); return; }
        if ($c === ')') { $this->tokens[] = new Token("RPAREN", null, $this->line); return; }
        if ($c === ',') { $this->tokens[] = new Token("COMMA", null, $this->line); return; }
        if ($c === ':') { $this->tokens[] = new Token("COLON", null, $this->line); return; }
        if ($c === '[') { $this->tokens[] = new Token("LBRACKET", null, $this->line); return; }
        if ($c === ']') { $this->tokens[] = new Token("RBRACKET", null, $this->line); return; }

        if ($c === '!') {
            $this->error("Carácter inesperado '!' (¿querías '!=' o '!!'?)");
            return;
        }

        $this->error("Carácter inesperado " . $this->describeChar($c));
    }





    private function peekNext(): string
    {
        return ($this->pos + 1 >= strlen($this->source)) ? "\0" : $this->source[$this->pos + 1];
    }



    // ---------------------------------

    private function isAtEnd(): bool
    {
        return $this->pos >= strlen($this->source);
    }

    private function advance(): string
    {
        return $this->source[$this->pos++];
    }

    private function peek(): string
    {
        return $this->isAtEnd() ? "\0" : $this->source[$this->pos];
    }

    private function match(string $expected): bool
    {
        if ($this->isAtEnd()) return false;
        if ($this->source[$this->pos] !== $expected) return false;

        $this->pos++;
        return true;
    }    

    private function string(): void
    {
        $this->insideString = true;

        $startLine = $this->line;
        $value = '';
        $closed = false;

        // Las cadenas pueden ocupar varias líneas (library/tools/analyzer.soul
        // lo usa); solo es un error si el fichero acaba sin cerrarla.
        while (!$this->isAtEnd()) {
            if ($this->peek() === '"') {
                $this->advance();
                $closed = true;
                break;
            }
            if ($this->peek() === "\n") {
                $this->line++;
            }
            $value .= $this->advance();
        }

        $this->insideString = false;

        if (!$closed) {
            $this->error("Cadena sin cerrar (empieza en la línea $startLine)", $startLine);
        }

        $this->tokens[] = new Token("STRING", $value, $startLine);
    }


    private function identifier(string $firstChar): void
    {
        $value = $firstChar;

        while (ctype_alnum($this->peek()) || $this->peek() === '_') {
            $value .= $this->advance();
        }

        // Tipos
        $types = [
            "int"       => "TYPE",
            "bool"      => "TYPE",
            "str"       => "TYPE",
            "statinfo"  => "TYPE",

            // Tipos de handle devueltos por builtins (ver Analyzer::analyzeCall).
            // Antes se lexaban como identificadores y el parser los descartaba
            // en silencio: `diriter it = listdir(x)` se leía como `it = listdir(x)`.
            "diriter"      => "TYPE",
            "overviewinfo" => "TYPE",
            "analyzeinfo"  => "TYPE",
            "netconn"      => "TYPE",

        ];


        if (isset($types[$value])) {
            $this->tokens[] = new Token("TYPE", $value, $this->line);
            return;
        }

        // Palabras clave
        $keywords = [
            "if" => "IF",
            "else" => "ELSE",
            "for" => "FOR",
            "in" => "IN",
            "while" => "WHILE",
            "parallel" => "PARALLEL",
            "break" => "BREAK",
            "continue" => "CONTINUE",
            "stop" => "STOP",
            "return" => "RETURN",
            "not" => "NOT",
        ];

        if (isset($keywords[$value])) {
            $this->tokens[] = new Token($keywords[$value], $value, $this->line);
            return;
        }

        // Booleanos
        if ($value === "true" || $value === "false") {
            $this->tokens[] = new Token("BOOL", $value === "true", $this->line);
            return;
        }

        // null literal
        if ($value === "null") {
            $this->tokens[] = new Token("NULL", null, $this->line);
            return;
        }

        // Identificador normal
        $this->tokens[] = new Token("IDENTIFIER", $value, $this->line);
    }


    private function number(string $firstChar): void
    {
        $value = $firstChar;

        while (ctype_digit($this->peek())) {
            $value .= $this->advance();
        }

        $this->tokens[] = new Token("INT", (int)$value, $this->line);
    }


}
