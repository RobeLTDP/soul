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

    private array $indentStack = [0];

    public function __construct(string $source)
    {
        // Normalizar finales de línea (CRLF / CR -> LF)
        $source = str_replace("\r\n", "\n", $source);
        $source = str_replace("\r", "\n", $source);

        $this->source = $source;
    }


    public function tokenize(): array
    {
        while (!$this->isAtEnd()) {
            $this->scanToken();
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
            return;
        }
    }


        // ===============================
        // INICIO DE LÍNEA (INDENT + COMENTARIOS)
        // ===============================
        if ($this->atLineStart) {

            // 1. Medir indentación SIN consumir
            $i = $this->pos;
            $spaces = 0;
            while ($i < strlen($this->source) && $this->source[$i] === ' ') {
                $spaces++;
                $i++;
            }

            // 2. Línea vacía (solo espacios + \n)
            if ($i < strlen($this->source) && $this->source[$i] === "\n") {
                $this->pos = $i + 1;
                $this->tokens[] = new Token("NEWLINE", null, $this->line);
                $this->line++;
                $this->atLineStart = true;
                return;
            }

            // 3. Calcular INDENT / DEDENT SIEMPRE
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
            }

            // 4. Línea comentada (ya con indent fijado)
            if ($i < strlen($this->source) && $this->source[$i] === '#') {
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

            // 5. Consumir espacios reales
            $this->pos += $spaces;
            $this->atLineStart = false;
        }

        // ===============================
        // TOKEN NORMAL
        // ===============================
        if ($this->isAtEnd()) return;

        $c = $this->advance();

        if ($c === ' ' || $c === "\t") return;

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
    }

    /*
        private function scanToken(): void
        {
            // 1. Manejo de indentación SOLO al inicio de línea
            if ($this->atLineStart) {
                $spaces = 0;

                // Contar espacios iniciales SIN consumir otros caracteres
                while (!$this->isAtEnd() && $this->peek() === ' ') {
                    $this->advance();
                    $spaces++;
                }

                // Línea vacía (solo espacios + \n): no afecta indentación
                if (!$this->isAtEnd() && $this->peek() === "\n") {
                    $this->advance(); // consumir \n
                    $this->tokens[] = new Token("NEWLINE", null, $this->line);
                    $this->line++;
                    $this->atLineStart = true;
                    return;
                }

                // Ajustar INDENT / DEDENT
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
                }

                $this->atLineStart = false;
            }

            // 2. Consumir el siguiente carácter real
            if ($this->isAtEnd()) return;

                $c = $this->advance();

                // ===============================
                // 1. COMENTARIOS (ANTES DE TODO)
                // ===============================

                // Comentario de bloque ##
                if ($c === '#' && $this->peek() === '#') {
                    $this->advance(); // segundo #

                    while (!$this->isAtEnd()) {
                        // cierre ##
                        if ($this->peek() === '#' &&
                            $this->pos + 1 < strlen($this->source) &&
                            $this->source[$this->pos + 1] === '#') {
                            $this->advance();
                            $this->advance();
                            break;
                        }

                        if ($this->peek() === "\n") {
                            $this->line++;
                            $this->atLineStart = true;
                        }

                        $this->advance();
                    }
                    return;
                }

                // Comentario de línea #
                if ($c === '#') {
                    while (!$this->isAtEnd() && $this->peek() !== "\n") {
                        $this->advance();
                    }
                    return;
                }

                // ===============================
                // 2. INDENTACIÓN
                // ===============================

                if ($this->atLineStart) {
                    $spaces = 0;

                    while (!$this->isAtEnd() && $this->peek() === ' ') {
                        $this->advance();
                        $spaces++;
                    }

                    if (!$this->isAtEnd() && $this->peek() === "\n") {
                        $this->advance();
                        $this->tokens[] = new Token("NEWLINE", null, $this->line);
                        $this->line++;
                        $this->atLineStart = true;
                        return;
                    }

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
                    }

                    $this->atLineStart = false;
                }


            // Ignorar espacios y tabs dentro de línea
            if ($c === ' ' || $c === "\t") {
                return;
            }

            // Nueva línea
            if ($c === "\n") {
                $this->tokens[] = new Token("NEWLINE", null, $this->line);
                $this->line++;
                $this->atLineStart = true;
                return;
            }

            // String literal
            if ($c === '"') {
                $this->string();
                return;
            }

            // Número
            if (ctype_digit($c)) {
                $this->number($c);
                return;
            }

            // Identificador o palabra clave
            if (ctype_alpha($c) || $c === '_') {
                $this->identifier($c);
                return;
            }

            // Operador !!
            if ($c === '!' && $this->match('!')) {
                $this->tokens[] = new Token("CHECK", "!!", $this->line);
                return;
            }

            // Comparadores
            if ($c === '>') {
                $this->tokens[] = new Token($this->match('=') ? "GE" : "GT", null, $this->line);
                return;
            }

            if ($c === '<') {
                $this->tokens[] = new Token($this->match('=') ? "LE" : "LT", null, $this->line);
                return;
            }

            if ($c === '=' && $this->match('=')) {
                $this->tokens[] = new Token("EQ", null, $this->line);
                return;
            }

            if ($c === '!' && $this->match('=')) {
                $this->tokens[] = new Token("NEQ", null, $this->line);
                return;
            }

            // Asignación
            if ($c === '=') { $this->tokens[] = new Token("EQUALS", null, $this->line); return; }

            // Operadores aritméticos
            if ($c === '+') { $this->tokens[] = new Token("PLUS", null, $this->line); return; }
            if ($c === '-') { $this->tokens[] = new Token("MINUS", null, $this->line); return; }
            if ($c === '*') { $this->tokens[] = new Token("STAR", null, $this->line); return; }
            if ($c === '/') { $this->tokens[] = new Token("SLASH", null, $this->line); return; }

            // Paréntesis
            if ($c === '(') { $this->tokens[] = new Token("LPAREN", null, $this->line); return; }
            if ($c === ')') { $this->tokens[] = new Token("RPAREN", null, $this->line); return; }

            // Coma y dos puntos
            if ($c === ',') { $this->tokens[] = new Token("COMMA", null, $this->line); return; }
            if ($c === ':') { $this->tokens[] = new Token("COLON", null, $this->line); return; }

            // Corchetes
            if ($c === '[') { $this->tokens[] = new Token("LBRACKET", null, $this->line); return; }
            if ($c === ']') { $this->tokens[] = new Token("RBRACKET", null, $this->line); return; }

            // Ignorar cualquier otro carácter
        }
    */



    /*
    private function scanToken(): void
    {
        $c = $this->advance();

        // Manejo de indentación (solo al inicio de línea)
        if ($this->atLineStart) {
            $this->atLineStart = false;

            $spaces = 0;

            // Contar espacios iniciales
            if ($c === ' ') {
                $spaces = 1;
                while ($this->peek() === ' ') {
                    $this->advance();
                    $spaces++;
                }
            }

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
            }

            // Si el primer carácter no era espacio, seguimos procesándolo
            if ($c !== ' ') {
                // seguimos con el flujo normal
            } else {
                return;
            }
        }


        // Ignorar espacios y tabs
        if ($c === ' ' || $c === "\t") {
            return;
        }

        // Nueva línea
        if ($c === "\n") {
            $this->tokens[] = new Token("NEWLINE", null, $this->line);
            $this->line++;
            $this->atLineStart = true;
            return;
        }


        // String literal
        if ($c === '"') {
            $this->string();
            return;
        }

        // Número
        if (ctype_digit($c)) {
            $this->number($c);
            return;
        }

        // Identificador o palabra clave
        if (ctype_alpha($c) || $c === '_') {
            $this->identifier($c);
            return;
        }

        // Operador !!
        if ($c === '!' && $this->match('!')) {
            $this->tokens[] = new Token("CHECK", "!!", $this->line);
            return;
        }

        // Operadores de comparación
        if ($c === '>') {
            $this->tokens[] = new Token(
                $this->match('=') ? "GE" : "GT",
                null,
                $this->line
            );
            return;
        }

        if ($c === '<') {
            $this->tokens[] = new Token(
                $this->match('=') ? "LE" : "LT",
                null,
                $this->line
            );
            return;
        }

        if ($c === '=' && $this->match('=')) {
            $this->tokens[] = new Token("EQ", null, $this->line);
            return;
        }

        if ($c === '!' && $this->match('=')) {
            $this->tokens[] = new Token("NEQ", null, $this->line);
            return;
        }

        // Asignación
        if ($c === '=') {
            $this->tokens[] = new Token("EQUALS", null, $this->line);
            return;
        }

        // Operador -
        if ($c === '-') {
            $this->tokens[] = new Token("MINUS", null, $this->line);
            return;
        }

        // Operador *
        if ($c === '*') {
            $this->tokens[] = new Token("STAR", null, $this->line);
            return;
        }

        // Operador /
        if ($c === '/') {
            $this->tokens[] = new Token("SLASH", null, $this->line);
            return;
        }

        // Paréntesis
        if ($c === '(') {
            $this->tokens[] = new Token("LPAREN", null, $this->line);
            return;
        }

        if ($c === ')') {
            $this->tokens[] = new Token("RPAREN", null, $this->line);
            return;
        }

        // Coma
        if ($c === ',') {
            $this->tokens[] = new Token("COMMA", null, $this->line);
            return;
        }

        if ($c === ':') {
            $this->tokens[] = new Token("COLON", null, $this->line);
            return;
        }


        if ($c === '[') { $this->tokens[] = new Token("LBRACKET", null, $this->line); return; }
        if ($c === ']') { $this->tokens[] = new Token("RBRACKET", null, $this->line); return; }



        // Ignorar cualquier otro carácter por ahora
    }
    */

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

        $value = '';

        while (!$this->isAtEnd() && $this->peek() !== '"') {
            $value .= $this->advance();
        }

        if (!$this->isAtEnd()) {
            $this->advance();
        }

        $this->insideString = false;
        $this->tokens[] = new Token("STRING", $value, $this->line);
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
