<?php

define('SOUL_ROOT', __DIR__);

require_once SOUL_ROOT . '/lexer/Lexer.php';
require_once SOUL_ROOT . '/parser/Parser.php';
require_once SOUL_ROOT . '/semantic/Analyzer.php';
require_once SOUL_ROOT . '/compiler/Lowerer.php';
require_once SOUL_ROOT . '/compiler/Optimizer.php';

require_once SOUL_ROOT . '/ir/OpCode.php';
require_once SOUL_ROOT . '/ir/IrTemp.php';
//require_once SOUL_ROOT . '/ir/IrLit.php';
//require_once SOUL_ROOT . '/ir/IrLabelRef.php';
require_once SOUL_ROOT . '/ir/IrOperand.php';
require_once SOUL_ROOT . '/ir/IrInstruction.php';
require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/IrPrinter.php';

$code = file_get_contents(__DIR__ . "/examples/test_opt.soul");

echo "=== LEXER ===\n";
$lexer = new Lexer($code);
$tokens = $lexer->tokenize();

echo "=== PARSER ===\n";
$parser = new Parser($tokens);
$ast = $parser->parseProgram();

echo "=== ANALYZER ===\n";
$analyzer = new Analyzer();
$analyzer->analyze($ast);

$errors = $analyzer->getErrors();
if ($errors) {
    echo "Semantic errors:\n";
    print_r($errors);
    exit(1);
}

echo "=== LOWERER (IR inicial) ===\n";
$lowerer = new Lowerer();
$ir = $lowerer->lower($ast);

IrPrinter::print($ir);

echo "\n=== OPTIMIZER aplicado ===\n";
$optimizer = new Optimizer();
$optimizer->optimize($ir);

IrPrinter::print($ir);

echo "\nTest completado.\n";
