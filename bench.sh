#!/bin/bash

echo "=== CONCAT (cadenas reales, 200k iteraciones) ==="
php soulc.php build examples/test_concat_real.soul
time ./out/test_concat_real

echo "=== BUILDSTR manual (cadenas reales, 200k iteraciones) ==="
php soulc.php build examples/test_buildstr_real.soul
time ./out/test_buildstr_real

echo "=== CONCAT optimizado por el compilador ==="
php soulc.php build examples/test_concat_real.soul
time ./out/test_concat_real
