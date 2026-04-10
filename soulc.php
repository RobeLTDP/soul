<?php

define('SOUL_ROOT', __DIR__);

// Flags globales
$dumpIR = in_array('--dump-ir', $argv, true);
$dumpC  = in_array('--dump-c',  $argv, true);
$trace  = in_array('--trace',   $argv, true);
$docker = in_array('--docker', $argv, true);

require_once __DIR__ . '/compiler/Compiler.php';
require_once __DIR__ . '/lexer/Lexer.php';
require_once __DIR__ . '/parser/Parser.php';
require_once __DIR__ . '/semantic/Analyzer.php';
require_once __DIR__ . '/planner/PlanAnalyzer.php';
require_once __DIR__ . '/planner/PlanRecorder.php';
require_once __DIR__ . '/cli/LibraryInspector.php';
require_once SOUL_ROOT . '/compiler/SoulProgramCreator.php';
require_once SOUL_ROOT . '/compiler/ProgramRunner.php';
require_once SOUL_ROOT . '/compiler/IrRunner.php';
require_once SOUL_ROOT . '/cli/ProgramInspector.php';
require_once SOUL_ROOT . '/cli/HelpScreen.php';


/* ----------------------------------------------------------
|  HELP / USAGE
-----------------------------------------------------------*/

function resolveSoulScript(string $name): ?string
{
    $candidates = [];

    // tal cual
    $candidates[] = $name;

    // con extensión
    if (!str_ends_with($name, '.soul')) {
        $candidates[] = $name . '.soul';
    }

    // rutas típicas
    $baseDirs = [
        getcwd(),
        SOUL_ROOT . '/scripts',
        SOUL_ROOT . '/library/scripts',
        SOUL_ROOT . '/library/toolbox',
    ];

    foreach ($baseDirs as $dir) {
        foreach ($candidates as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                return realpath($path);
            }
        }
    }

    return null;
}



function usage(): void
{
    $rojo   = "\033[31m";
    $verde  = "\033[92m";
    $blanco = "\033[97m";
    $reset  = "\033[0m";

    echo "\n";
    echo $rojo;    
    echo "████████  ████████  ██    ██  ██      \n";
    echo "██        ██    ██  ██    ██  ██      \n";
    echo "████████  ██    ██  ██    ██  ██      \n";
    echo "      ██  ██    ██  ██    ██  ██      \n";
    echo "████████  ████████  ████████  ███████ \n";
    echo $reset;
    echo "\n";

    echo $blanco . "Welcome to Soul CLI. Available commands:\n\n" . $reset;

    echo "  " . $verde . "soul ?" . $reset . "                          Show short help\n";
    echo "  " . $verde . "soul help" . $reset . "                       Show full documentation\n";
    echo "  " . $verde . "soul plan <file.soul>" . $reset . "             Parse + analyze (no compile)\n";
    echo "  " . $verde . "soul build <file.soul>" . $reset . "            Compile script to native binary\n";
    echo "  " . $verde . "soul run [--parallel=N] <file.soul|name>" . $reset . "  Run binary (optionally multi-worker)\n";
    echo "  " . $verde . "soul library [folder]" . $reset . "            Browse script library with descriptions\n";
    echo "  " . $verde . "soul program \"name\" \"A > B > C\"" . $reset . " Create a pipeline program (.sprog)\n";
    echo "  " . $verde . "soul programs" . $reset . "                    List all pipelines\n";
    echo "  " . $verde . "soul execute <program>" . $reset . "           Execute a stored pipeline\n\n";

    echo "For detailed help:\n";
    echo "    " . $verde . "soul help" . $reset . "\n\n";
}


/* ----------------------------------------------------------
|  HELP COMMAND
-----------------------------------------------------------*/

$command = $argv[1] ?? null;
$input   = $argv[2] ?? null;

if ($command === null) {
    usage();
    exit(1);
}

if ($command === '?' || $command === 'help') {
    $help = new HelpScreen();
    $help->show();
    exit;
}


/* ----------------------------------------------------------
|  HELPER: find binary inside /out recursively
-----------------------------------------------------------*/

function findBinaryInOut(string $name): ?string
{
    $outRoot = SOUL_ROOT . '/out';

    if (!is_dir($outRoot)) {
        return null;
    }

    $latestPath = null;
    $latestTime = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if ($file->getBasename() !== $name) continue;

        $mtime = $file->getMTime();

        if ($mtime > $latestTime) {
            $latestTime = $mtime;
            $latestPath = $file->getPathname();
        }
    }

    return $latestPath;
}


function findBinaryForScript(string $scriptPath): ?string
{
    $root = realpath(SOUL_ROOT);
    $scriptPath = realpath($scriptPath);

    if ($root === false || $scriptPath === false) {
        return null;
    }

    // asegurar que el script está dentro del proyecto
    if (!str_starts_with($scriptPath, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    // obtener ruta relativa correcta
    $relative = substr($scriptPath, strlen($root) + 1);

    // quitar extensión
    $relative = preg_replace('/\.soul$/', '', $relative);

    $binPath = $root . '/out/' . $relative;

    return is_file($binPath) ? $binPath : null;
}


/* ----------------------------------------------------------
|  soul plan
-----------------------------------------------------------*/

if ($command === 'plan') {

    if ($input === null) {
        echo "ERROR: Missing script for plan.\n";
        usage();
        exit(1);
    }

    $scriptPath = resolveSoulScript($input);

    if ($scriptPath === null) {
        echo "ERROR: Script not found: $input\n";
        exit(1);
    }

    $compiler = new Compiler();
    $ir = $compiler->compileToIrOnly($scriptPath);

    $recorder = new PlanRecorder();

    $runner = new IrRunner();
    $runner->run($ir, trace: false, planMode: true, recorder: $recorder);

    $recorder->printSummary();

    exit(0);
}



/* ----------------------------------------------------------
|  soul run  (con --parallel=N)
-----------------------------------------------------------*/

if ($command === 'run') {

    if ($input === null) {
        usage();
        exit(1);
    }

    // 1) Detectar flag --parallel=N (solo en $argv[2] para simplificar)
    $parallel = 1;
    $scriptArg = null;

    if (str_starts_with($input, '--parallel=')) {
        $raw = substr($input, strlen('--parallel='));
        $parallel = (int)$raw;
        if ($parallel < 1) $parallel = 1;

        $scriptArg = $argv[3] ?? null;
    } else {
        $scriptArg = $input;
    }

    if ($scriptArg === null) {
        echo "ERROR: Missing script after run / --parallel.\n";
        usage();
        exit(1);
    }

    // Argumentos extra pasados al script Soul
    $scriptIndex = array_search($scriptArg, $argv, true);
    $extraArgs = [];

    if ($scriptIndex !== false) {
        $extraArgs = array_slice($argv, $scriptIndex + 1);
    }

    // 2) Deducir el nombre de binario a partir del script (si es .soul)
    if (str_ends_with($scriptArg, '.soul')) {
        $name = pathinfo($scriptArg, PATHINFO_FILENAME);
    } else {
        $name = $scriptArg;
    }

    // 3) Buscar el binario en todo el árbol /out
    $scriptPath = resolveSoulScript($name);

    if (!$scriptPath) {
        echo "ERROR: Script not found: $name\n";
        exit(1);
    }

    $bin = findBinaryInOut($name);

    @chmod($bin, 0755);
    $cmd = escapeshellarg($bin);

    foreach ($extraArgs as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    // 4) Ejecución normal (sin paralelismo)
    if ($parallel <= 1) {
        echo "Soul program '$name' launched:\n\n";
        passthru($cmd, $exitCode);
        exit($exitCode);
    }

    // 5) Ejecución en paralelo: N workers
    echo "Soul program '$name' launched with {$parallel} workers:\n\n";

    $procs = [];
    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    for ($i = 0; $i < $parallel; $i++) {
        $env = $_ENV;
        if (!is_array($env)) {
            $env = [];
        }

        $env['SOUL_WORKER_ID']    = (string)$i;
        $env['SOUL_WORKER_COUNT'] = (string)$parallel;

        $proc = proc_open($cmd, $descriptorSpec, $pipes, getcwd(), $env);

        if (!is_resource($proc)) {
            echo "ERROR: Could not start worker $i.\n";
            // cerrar los ya abiertos
            foreach ($procs as $p) {
                if (is_resource($p)) {
                    proc_terminate($p);
                }
            }
            exit(1);
        }

        $procs[] = $proc;
    }

    // Esperar a que terminen todos los workers
    $finalExit = 0;
    foreach ($procs as $p) {
        $code = proc_close($p);
        if ($code !== 0 && $finalExit === 0) {
            $finalExit = $code;
        }
    }

    exit($finalExit);
}


/* ----------------------------------------------------------
|  soul execute   (programas .sprog)
-----------------------------------------------------------*/

if ($command === 'execute') {

    if ($input === null) {
        echo "ERROR: Debe indicar el nombre del programa.\n";
        echo "Uso: soul execute <nombreProgram>\n";
        exit(1);
    }

    // Normalizar nombre
    if (str_ends_with($input, '.sprog')) {
        $progName = basename($input, '.sprog');
        $progPath = SOUL_ROOT . "/programs/" . $input;
    } else {
        $progName = $input;
        $progPath = SOUL_ROOT . "/programs/" . $input . ".sprog";
    }

    if (!is_file($progPath)) {
        echo "ERROR: No existe el programa: $progPath\n";
        exit(1);
    }

    // CWD donde vive el .sprog (por si usa paths relativos internos)
    chdir(dirname($progPath));

    $runner = new ProgramRunner(SOUL_ROOT);
    $runner->run($progName);
    exit;
}


/* ----------------------------------------------------------
|  soul build
-----------------------------------------------------------*/

if ($command === 'build') {

    if ($input === null) {
        usage();
        exit(1);
    }

    $scriptPath = resolveSoulScript($input);

    if ($scriptPath === null) {
        echo "ERROR: Script not found: $input\n";
        exit(1);
    }

    $outDir = __DIR__ . '/out';

    $compiler = new Compiler(dumpIR: $dumpIR, dumpC: $dumpC, trace: $trace);
    //$result = $compiler->compileFile($scriptPath, $outDir, runGcc: !$dumpIR && !$dumpC);
    $result = $compiler->compileFile($scriptPath,    $outDir,    runGcc: !$dumpIR && !$dumpC,    dockerMode: $docker);

    echo "BUILD OK\n";
    echo "C:   {$result['cPath']}\n";
    echo "BIN: {$result['binPath']}\n";

    exit(0);
}


/* ----------------------------------------------------------
|  soul library
-----------------------------------------------------------*/

if ($command === 'library') {

    $targetDir = $argv[2] ?? "";

    $lib = new LibraryInspector(SOUL_ROOT, $targetDir);
    $lib->run();
    exit;
}


/* ----------------------------------------------------------
|  soul program
-----------------------------------------------------------*/

if ($command === 'program') {

    if (!isset($argv[2]) || !isset($argv[3])) {
        echo "ERROR: Sintaxis inválida.\n";
        echo "Use: soul program \"nombre\" \"scriptA > scriptB > scriptC\"\n";
        exit(1);
    }

    $name = trim($argv[2], "\"'");
    $pipeline = $argv[3];

    $creator = new SoulProgramCreator(SOUL_ROOT);
    $creator->createProgram($name, $pipeline);
    exit;
}


/* ----------------------------------------------------------
|  soul programs
-----------------------------------------------------------*/

if ($command === 'programs') {

    $insp = new ProgramInspector(SOUL_ROOT);
    $insp->run();
    exit;
}


/* ----------------------------------------------------------
|  DEFAULT: build .soul
-----------------------------------------------------------*/

$scriptPath = resolveSoulScript($command);

if ($scriptPath === null) {
    echo "ERROR: Script not found: $command\n";
    usage();
    exit(1);
}

$outDir = $argv[2] ?? (__DIR__ . '/out');

try {
    $compiler = new Compiler(dumpIR: $dumpIR, dumpC: $dumpC, trace: $trace);
    //$result = $compiler->compileFile($scriptPath, $outDir, runGcc: !$dumpIR && !$dumpC);
    $result = $compiler->compileFile(    $scriptPath,    $outDir,    runGcc: !$dumpIR && !$dumpC,    dockerMode: $docker);

    echo "OK\n";
    echo "C:   {$result['cPath']}\n";
    echo "BIN: {$result['binPath']}\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
