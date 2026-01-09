<?php

class ProgramRunner
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function run(string $programName, array $params = []): void
    {
        $path = $this->root . "/programs/{$programName}.sprog";

        if (!is_file($path)) {
            echo "ERROR: El programa '{$programName}' no existe en /programs.\n";
            return;
        }

        $json = json_decode(file_get_contents($path), true);
        if (!$json) {
            echo "ERROR: El archivo .sprog está corrupto.\n";
            return;
        }

        echo "== Ejecutando programa Soul: {$json['name']} ==\n";
        echo "Creado: {$json['created_at']}\n\n";

        $steps = $json['steps'];
        $compiler = new Compiler();

        foreach ($steps as $index => $step) {
            $script = $step['script'];
            $scriptPath = $this->root . "/library/{$script}";

            echo "Paso " . ($index+1) . ": {$script}\n";

            if (!is_file($scriptPath)) {
                echo "  ERROR: Script no encontrado: {$script}\n";
                return;
            }

            // Compilar si no existe el binario
            $binName = pathinfo($script, PATHINFO_FILENAME);
            $binPath = $this->root . "/out/{$binName}";

            if (!file_exists($binPath)) {
                echo "  Compilando {$script}...\n";
                $compiler->compileFile($scriptPath, $this->root . "/out", true);
            }

            @chmod($binPath, 0755);

            echo "  Ejecutando...\n";
            passthru($binPath, $exitCode);

            if ($exitCode !== 0) {
                echo "  ERROR: El script falló. Cancelando pipeline.\n";
                return;
            }

            echo "  OK\n\n";
        }

        echo "== Programa finalizado correctamente ==\n\n";
    }
}
