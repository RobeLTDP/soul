<?php

class LibraryInspector
{
    private string $baseDir;
    private string $targetDir;

    public function __construct(string $root, ?string $targetDir)
    {
        $this->baseDir = rtrim($root, DIRECTORY_SEPARATOR);

        // CASO 1: sin parámetro → usar "library"
        if ($targetDir === "" || $targetDir === null) {
            $this->targetDir = "library";
            return;
        }

        // Normalizar
        $clean = rtrim($targetDir, DIRECTORY_SEPARATOR);

        // CASO 2: ruta absoluta (empieza por /)
        if (str_starts_with($clean, "/")) {
            $this->targetDir = $clean;
            return;
        }

        // CASO 3: contiene barra → se usa tal cual (ruta relativa “normal”)
        if (str_contains($clean, "/")) {
            $this->targetDir = $clean;
            return;
        }

        // CASO 4: nombre simple → interpretarlo como subcarpeta de library/
        $this->targetDir = "library/" . $clean;
    }

    public function run(): void
    {
        $full = $this->baseDir . "/" . $this->targetDir;

        echo "== Soul Library ==\n";
        echo "Directorio base: {$this->targetDir}\n\n";

        if (!is_dir($full)) {
            echo "ERROR: El directorio '{$this->targetDir}' no existe.\n\n\n";
            return;
        }

        $label = rtrim($this->targetDir, "/") . "/";
        $items = $this->collectEntries($full);

        echo $label . "\n";

        if (empty($items)) {
            echo "  (vacío)\n\n\n";
            return;
        }

        $this->printTree($full, $items, "");

        echo "\n\n";
    }

    /**
     * Recorre el directorio y devuelve entradas relevantes (dirs + .soul).
     *
     * @return array<int,array{name:string,path:string,isDir:bool}>
     */
    private function collectEntries(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) return [];

        $items = [];

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") continue;

            $fullPath = $dir . "/" . $entry;

            if (is_dir($fullPath)) {
                $items[] = [
                    'name'  => $entry,
                    'path'  => $fullPath,
                    'isDir' => true,
                ];
                continue;
            }

            // Solo archivos .soul
            if (is_file($fullPath) && $this->endsWith($entry, ".soul")) {
                $items[] = [
                    'name'  => $entry,
                    'path'  => $fullPath,
                    'isDir' => false,
                ];
            }
        }

        // Orden: primero carpetas, luego ficheros; alfabético en cada grupo
        usort($items, function ($a, $b) {
            if ($a['isDir'] === $b['isDir']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['isDir'] ? -1 : 1;
        });

        return $items;
    }

    /**
     * Imprime recursivamente el árbol.
     *
     * @param string $dir    directorio actual
     * @param array  $items  hijos directos de $dir (de collectEntries)
     * @param string $prefix prefijo visual (│   /    etc.)
     */
    private function printTree(string $dir, array $items, string $prefix): void
    {
        $count = count($items);

        // Colores ANSI
        $dirColor  = "\033[94m";  // azul
        $fileColor = "\033[92m";  // verde
        $docColor  = "\033[97m";  // blanco
        $reset     = "\033[0m";

        foreach ($items as $idx => $item) {
            $isLast    = ($idx === $count - 1);
            $connector = $isLast ? "└── " : "├── ";
            $childPref = $prefix . ($isLast ? "    " : "│   ");

            if ($item['isDir']) {
                // Carpeta en azul
                echo $prefix . $connector . $dirColor . $item['name'] . "/" . $reset . "\n";

                // Recolectar recursivamente
                $subItems = $this->collectEntries($item['path']);
                if (!empty($subItems)) {
                    $this->printTree($item['path'], $subItems, $childPref);
                }

            } else {
                // Fichero .soul
                $docLines = $this->extractDocBlock($item['path']); // ?array

                // Nombre del fichero en azul
                echo $prefix . $connector . $fileColor . $item['name'] . $reset . "\n";

                // Descripción en blanco, indentada debajo del fichero
                if ($docLines !== null) {
                    foreach ($docLines as $line) {
                        if ($line === '') continue;
                        echo $childPref . "    " . $docColor . $line . $reset . "\n";
                    }
                }
            }
        }
    }

    /**
     * Lee las primeras líneas del fichero y extrae el bloque de documentación.
     * Formato soportado:
     *
     * ##
     * Línea 1
     * Línea 2
     * ##
     *
     * Devuelve array de líneas o null si no hay docblock.
     *
     * @return string[]|null
     */
    private function extractDocBlock(string $path): ?array
    {
        $fp = @fopen($path, "r");
        if (!$fp) return null;

        $lines = [];
        $inBlock = false;

        while (($raw = fgets($fp)) !== false) {
            // Quitar BOM si está presente
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $trim = trim($line);

            // Inicio de bloque “##”
            if (!$inBlock) {
                if ($trim === "##") {
                    $inBlock = true;
                    continue;
                }

                // Permitimos líneas vacías antes del docblock
                if ($trim === '') {
                    continue;
                }

                // Primera línea no vacía y no "##" → no hay docblock
                break;
            }

            // Dentro del bloque
            if ($inBlock) {
                // Fin de bloque “##”
                if ($trim === "##") {
                    break;
                }

                // Cualquier otra línea es contenido
                $lines[] = $trim;
            }
        }

        fclose($fp);

        if (empty($lines)) {
            return null;
        }

        return $lines;
    }

    /**
     * Helper para no depender de PHP 8+ estrictamente.
     */
    private function endsWith(string $haystack, string $needle): bool
    {
        $len = strlen($needle);
        if ($len === 0) return true;
        return substr($haystack, -$len) === $needle;
    }
}
