<?php
// Minimal front-end driver used by the test runner: lexer -> parser -> analyzer.
// Returns the list of errors as strings (empty list = program accepted).

if (!defined('SOUL_ROOT')) {
    define('SOUL_ROOT', dirname(__DIR__));
}

require_once SOUL_ROOT . '/lexer/Lexer.php';
require_once SOUL_ROOT . '/parser/Parser.php';
require_once SOUL_ROOT . '/semantic/Analyzer.php';

function soul_frontend_errors(string $code): array
{
    $errors = [];

    $lexer  = new Lexer($code);
    $tokens = $lexer->tokenize();
    if (method_exists($lexer, 'getErrors')) {
        foreach ($lexer->getErrors() as $e) {
            $errors[] = $e;
        }
    }

    $parser = new Parser($tokens);
    $ast    = $parser->parseProgram();
    foreach ($parser->getErrors() as $e) {
        $errors[] = $e;
    }

    $analyzer = new Analyzer();
    $analyzer->analyze($ast);
    foreach ($analyzer->getErrors() as $e) {
        $errors[] = $e;
    }

    return $errors;
}
