<?php
/**
 * Soul test runner (no dependencies beyond PHP; gcc only for the e2e tests).
 *
 *   php tests/run.php              # everything
 *   php tests/run.php --no-e2e     # front-end only (no gcc needed)
 *   php tests/run.php --filter=tab # only tests whose name contains "tab"
 *
 * Test files carry their expectations in leading comments:
 *
 *   tests/cases/*.soul   front-end (lexer + parser + analyzer)
 *       # expect: ok                 the program must be accepted
 *       # expect-error: <text>       some error message must contain <text>
 *                                    (may be repeated; "Línea N: ..." pins a line)
 *
 *   tests/plan/*.soul    run `soul plan` and check its report
 *       # expect-plan: <text>        the plan output must contain <text>
 *
 *   tests/e2e/*.soul     compile to a native binary and run it
 *       # expect-output: <text>      stdout must equal the expect-output lines
 *       # expect-build-error: <text> the build must fail and print <text>
 *
 * Every library/tools/*.soul must also be accepted by the front-end.
 */

require_once __DIR__ . '/frontend.php';

$noE2e  = in_array('--no-e2e', $argv, true);
$filter = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--filter=')) $filter = substr($a, 9);
}

$pass = 0;
$fail = 0;
$skip = 0;

function t_report(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name\n";
        if ($detail !== '') {
            foreach (explode("\n", rtrim($detail)) as $l) echo "          $l\n";
        }
    }
}

/** @return array{ok:bool, errors:string[], outputs:string[], buildErrors:string[]} */
function t_directives(string $code): array
{
    $d = ['ok' => false, 'errors' => [], 'outputs' => [], 'buildErrors' => []];
    foreach (preg_split('/\r?\n/', $code) as $line) {
        if (!str_starts_with($line, '#')) continue;
        if (preg_match('/^#\s*expect:\s*ok\s*$/', $line)) {
            $d['ok'] = true;
        } elseif (preg_match('/^#\s*expect-error:\s?(.*)$/', $line, $m)) {
            $d['errors'][] = $m[1];
        } elseif (preg_match('/^#\s*expect-output:\s?(.*)$/', $line, $m)) {
            $d['outputs'][] = $m[1];
        } elseif (preg_match('/^#\s*expect-build-error:\s?(.*)$/', $line, $m)) {
            $d['buildErrors'][] = $m[1];
        }
    }
    return $d;
}

function t_wanted(string $name): bool
{
    global $filter;
    return $filter === null || str_contains($name, $filter);
}

// ---------------------------------------------------------------------------
echo "Front-end cases (tests/cases)\n";
foreach (glob(__DIR__ . '/cases/*.soul') as $file) {
    $name = 'cases/' . basename($file);
    if (!t_wanted($name)) continue;

    $code   = file_get_contents($file);
    $d      = t_directives($code);
    $errors = soul_frontend_errors($code);

    if ($d['ok']) {
        t_report($name, $errors === [], "expected no errors, got:\n" . implode("\n", $errors));
        continue;
    }

    if (empty($d['errors'])) {
        t_report($name, false, "test file has no '# expect:' / '# expect-error:' directive");
        continue;
    }

    $missing = [];
    foreach ($d['errors'] as $needle) {
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, $needle)) { $found = true; break; }
        }
        if (!$found) $missing[] = $needle;
    }
    $detail = '';
    if ($missing) {
        $detail = "missing error(s): " . implode(' | ', $missing) . "\nactual: " . ($errors ? implode(' | ', $errors) : '(none)');
    }
    t_report($name, $missing === [], $detail);
}

// ---------------------------------------------------------------------------
echo "\nLibrary tools must be accepted (library/tools)\n";
foreach (glob(SOUL_ROOT . '/library/tools/*.soul') as $file) {
    $name = 'library/' . basename($file);
    if (!t_wanted($name)) continue;
    $errors = soul_frontend_errors(file_get_contents($file));
    t_report($name, $errors === [], implode("\n", $errors));
}

// ---------------------------------------------------------------------------
echo "\nEnd-to-end (tests/e2e)\n";
$hasGcc = trim((string)shell_exec('command -v gcc 2>/dev/null')) !== '';
if ($noE2e || !$hasGcc) {
    $skip++;
    echo "  SKIP  " . ($noE2e ? "--no-e2e given" : "gcc not found") . "\n";
} else {
    foreach (glob(__DIR__ . '/e2e/*.soul') as $file) {
        $base = basename($file, '.soul');
        $name = 'e2e/' . $base . '.soul';
        if (!t_wanted($name)) continue;

        $d = t_directives(file_get_contents($file));

        // Run from the repo root so the CLI resolves the relative script path.
        $cmd = 'cd ' . escapeshellarg(SOUL_ROOT) . ' && php soulc.php build '
             . escapeshellarg('tests/e2e/' . $base . '.soul') . ' 2>&1';
        $buildOut = [];
        exec($cmd, $buildOut, $buildCode);
        $buildText = implode("\n", $buildOut);

        if (!empty($d['buildErrors'])) {
            $ok = $buildCode !== 0;
            foreach ($d['buildErrors'] as $needle) {
                if (!str_contains($buildText, $needle)) $ok = false;
            }
            t_report($name, $ok, "exit=$buildCode, output:\n" . $buildText);
            continue;
        }

        if ($buildCode !== 0) {
            t_report($name, false, "build failed:\n" . $buildText);
            continue;
        }

        $bin = SOUL_ROOT . '/out/' . $base;
        $run = [];
        exec('timeout 10 ' . escapeshellarg($bin) . ' 2>&1', $run, $runCode);
        $actual   = trim(implode("\n", $run));
        $expected = trim(implode("\n", $d['outputs']));
        t_report($name, $actual === $expected, "expected: " . json_encode($expected) . "\nactual:   " . json_encode($actual));
    }
}

// ---------------------------------------------------------------------------
echo "\nPlan mode (tests/plan)\n";
foreach (glob(__DIR__ . '/plan/*.soul') as $file) {
    $base = basename($file, '.soul');
    $name = 'plan/' . $base . '.soul';
    if (!t_wanted($name)) continue;

    $code = file_get_contents($file);
    $want = [];
    foreach (preg_split('/\r?\n/', $code) as $line) {
        if (preg_match('/^#\s*expect-plan:\s?(.*)$/', $line, $m)) $want[] = $m[1];
    }

    $out = [];
    exec('cd ' . escapeshellarg(SOUL_ROOT) . ' && php soulc.php plan '
        . escapeshellarg('tests/plan/' . $base . '.soul') . ' 2>&1', $out, $code2);
    $text = implode("\n", $out);

    $missing = array_values(array_filter($want, fn($w) => !str_contains($text, $w)));
    t_report($name, $want !== [] && $missing === [],
        $want === [] ? "no '# expect-plan:' directive"
                     : "missing: " . implode(' | ', $missing) . "\n--- output ---\n" . $text);
}

// ---------------------------------------------------------------------------
echo "\nRuntime behaviour (compiled tools)\n";
if ($noE2e || !$hasGcc) {
    echo "  SKIP  " . ($noE2e ? "--no-e2e given" : "gcc not found") . "\n";
} elseif (t_wanted('runtime/analyzer_top')) {
    // analyzer must list the biggest files largest-first, and no empty slots.
    $dir = sys_get_temp_dir() . '/soul-test-' . getmypid();
    @mkdir($dir . '/sub', 0777, true);
    foreach (['a.txt' => 300, 'b.txt' => 900, 'sub/c.log' => 100, 'sub/d.log' => 700, 'e.txt' => 500] as $f => $n) {
        file_put_contents("$dir/$f", str_repeat('x', $n));
    }
    $o = [];
    exec('cd ' . escapeshellarg(SOUL_ROOT) . ' && php soulc.php build library/tools/analyzer.soul 2>&1', $o, $rc);
    $bin = SOUL_ROOT . '/out/tools/analyzer';
    if ($rc !== 0 || !is_file($bin)) {
        t_report('runtime/analyzer_top', false, "build failed:\n" . implode("\n", $o));
    } else {
        $o = [];
        exec(escapeshellarg($bin) . ' --folder ' . escapeshellarg($dir) . ' --top 10 2>&1', $o);
        $text = implode("\n", $o);
        preg_match_all('/^' . preg_quote($dir, '/') . '\/(\S+) -> (\d+)$/m', $text, $m, PREG_SET_ORDER);
        $got = array_map(fn($x) => $x[1] . '=' . $x[2], $m);
        $want = ['b.txt=900', 'sub/d.log=700', 'e.txt=500', 'a.txt=300', 'sub/c.log=100'];
        $emptyLines = preg_match('/^ -> 0$/m', $text);
        t_report('runtime/analyzer_top', $got === $want && !$emptyLines,
            "expected: " . implode(', ', $want) . "\nactual:   " . implode(', ', $got) . ($emptyLines ? "\n(empty ' -> 0' lines present)" : ''));
    }
    shell_exec('rm -rf ' . escapeshellarg($dir));
}

// ---------------------------------------------------------------------------
if (!$noE2e && $hasGcc && t_wanted('runtime/delete_bigger_than')) {
    // delete-bigger-than must honour --minSize, and refuse to run without it.
    $o = [];
    exec('cd ' . escapeshellarg(SOUL_ROOT) . ' && php soulc.php build library/tools/delete-bigger-than.soul 2>&1', $o, $rc);
    $bin = SOUL_ROOT . '/out/tools/delete-bigger-than';
    if ($rc !== 0 || !is_file($bin)) {
        t_report('runtime/delete_bigger_than', false, "build failed:\n" . implode("\n", $o));
    } else {
        $mk = function () {
            $d = sys_get_temp_dir() . '/soul-dbt-' . getmypid() . '-' . mt_rand();
            mkdir($d, 0777, true);
            file_put_contents("$d/small.bin", str_repeat('x', 100));
            file_put_contents("$d/big.bin", str_repeat('x', 5000));
            return $d;
        };
        $names = fn($d) => array_values(array_diff(scandir($d), ['.', '..']));

        $d1 = $mk();
        exec(escapeshellarg($bin) . ' --folder ' . escapeshellarg($d1) . ' --password CONFIRM_DELETE --minSize 1000 2>&1');
        $with = $names($d1);

        $d2 = $mk();
        exec(escapeshellarg($bin) . ' --folder ' . escapeshellarg($d2) . ' --password CONFIRM_DELETE 2>&1');
        $without = $names($d2);

        sort($without);
        $ok = $with === ['small.bin'] && $without === ['big.bin', 'small.bin'];
        t_report('runtime/delete_bigger_than', $ok,
            "with --minSize 1000 left: [" . implode(', ', $with) . "] (want [small.bin])\n" .
            "without --minSize left: [" . implode(', ', $without) . "] (want [big.bin, small.bin])");
        shell_exec('rm -rf ' . escapeshellarg($d1) . ' ' . escapeshellarg($d2));
    }
}

echo "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail === 0 ? 0 : 1);
