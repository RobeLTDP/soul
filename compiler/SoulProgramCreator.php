<?php

class SoulProgramCreator
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function createProgram(string $name, string $pipeline): void
    {
        // Convertir a la sintaxis antigua que ya funciona:
        //   "nombre": scriptA > scriptB > scriptC
        $syntax = "{$name}: {$pipeline}";
        $this->createFromSyntax($syntax);
    }


    /**
     * Recibe sintaxis:
     *   "limpiarCache": guardarlog > ultimos100 > enviarreporte
     */
    public function createFromSyntax(string $input): void
    {
        $input = trim($input);

        // Separar nombre y pipeline
        $parts = explode(':', $input, 2);
        if (count($parts) !== 2) {
            echo "ERROR: Sintaxis inválida. Use: soul program \"nombre\": scriptA > scriptB\n";
            return;
        }

        $rawName = trim($parts[0]);
        $pipeline = trim($parts[1]);

        // Quitar comillas del nombre
        $name = trim($rawName, "\"' ");

        if ($name === '') {
            echo "ERROR: El nombre del programa está vacío.\n";
            return;
        }

        // Extraer scripts separados por >
        $scripts = array_filter(array_map('trim', explode('>', $pipeline)));

        if (empty($scripts)) {
            echo "ERROR: Debe incluir al menos 1 script.\n";
            return;
        }

        // Validar cada script
        $steps = [];
        foreach ($scripts as $sc) {

            // Normalizar extensión .soul
            if (!str_ends_with($sc, '.soul')) {
                $sc = $sc . '.soul';
            }

            // Buscarlo en /library y subcarpetas
            $found = $this->findScript($sc);
            if ($found === null) {
                echo "ERROR: No se encuentra el script '{$sc}' en la carpeta library.\n";
                echo "Asegúrate de que existe como /library/algo/{$sc} o /library/{$sc}\n\n";
                return;
            }

            // Guardar ruta relativa dentro del programa
            $steps[] = ['script' => $found];
        }


        // Guardar JSON
        $program = [
            'name' => $name,
            'created_at' => gmdate('c'),
            'steps' => $steps
        ];

        $dir = $this->root . "/programs";
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . "/" . $name . ".sprog";

        file_put_contents($path, json_encode($program, JSON_PRETTY_PRINT));

        echo "Programa Soul creado:\n";
        echo " - Nombre: {$name}\n";
        echo " - Archivo: programs/{$name}.sprog\n";
        echo " - Pasos: " . count($steps) . "\n\n";
    }

    /**
     * Busca un script dentro de /library y subcarpetas.
     * Devuelve la ruta relativa (ej: "registro_logs/log_files_2.soul")
     * o null si no existe.
     */
    private function findScript(string $name): ?string
    {
        $lib = $this->root . "/library";

        // 1) Buscar directamente en library/
        $direct = $lib . "/" . $name;
        if (is_file($direct)) {
            return $name; // ruta relativa
        }

        // 2) Buscar recursivamente en subcarpetas
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getFilename() === $name) {
                // ruta relativa desde library/
                $relative = substr($file->getPathname(), strlen($lib) + 1);
                return $relative;
            }
        }

        return null; // no encontrado
    }


}
