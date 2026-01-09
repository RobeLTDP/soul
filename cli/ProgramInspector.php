<?php

class ProgramInspector
{
    private string $programDir;

    public function __construct(string $root)
    {
        $this->programDir = rtrim($root, "/") . "/programs";
    }

    public function run(): void
    {
        $dir = $this->programDir;

        echo "== Soul Programs ==\n";
        echo "Directorio: programs/\n\n";

        if (!is_dir($dir)) {
            echo "No existe la carpeta programs/.\n";
            return;
        }

        $files = scandir($dir);
        $items = [];

        foreach ($files as $f) {
            if ($f === "." || $f === "..") continue;
            if (str_ends_with($f, ".sprog")) {
                $items[] = $f;
            }
        }

        if (empty($items)) {
            echo "(No hay programas creados aún)\n";
            return;
        }

        // Colores
        $progColor = "\033[93m";   // amarillo claro
        $pipeColor = "\033[97m";   // blanco
        $reset     = "\033[0m";

        foreach ($items as $file) {

            echo "• " . $progColor . $file . $reset . "\n";

            $path = $dir . "/" . $file;
            $content = trim(@file_get_contents($path));

            if (!$content) {
                echo "    (vacío)\n\n";
                continue;
            }

            // Mostrar cada línea del pipeline
            $parts = explode(">", $content);
            $parts = array_map('trim', $parts);

            foreach ($parts as $p) {
                echo "    → " . $pipeColor . $p . $reset . "\n";
            }

            echo "\n";
        }
    }
}
