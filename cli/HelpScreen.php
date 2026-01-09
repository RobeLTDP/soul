<?php

class HelpScreen
{
    public function show(): void
    {
        // Colores
        $rojo  = "\033[31m";
        $verde = "\033[92m";
        $blanco = "\033[97m";
        $reset = "\033[0m";
        echo "\n";
        echo $rojo;    
        echo "████████  ████████  ██    ██  ██      \n";
        echo "██        ██    ██  ██    ██  ██      \n";
        echo "████████  ██    ██  ██    ██  ██      \n";
        echo "      ██  ██    ██  ██    ██  ██      \n";
        echo "████████  ████████  ████████  ███████ \n";
        echo $reset;
        echo "\n";

        $title = "\033[96m";   // cyan brillante
        $cmd   = "\033[92m";   // verde claro
        $desc  = "\033[97m";   // blanco
        $reset = "\033[0m";

        echo "\n";
        echo $title . "=== Soul CLI Help ===" . $reset . "\n\n";

        echo $desc . "Comandos principales:" . $reset . "\n\n";

        $this->line($cmd, "soul build <file.soul>", 
            "Compila un programa Soul a C y lo convierte en un binario nativo.");

        $this->line($cmd, "soul run <file.soul>",
            "Ejecuta un programa previamente compilado con soul build.");

        $this->line($cmd, "soul plan <file.soul>",
            "Muestra las acciones previstas del script (sin ejecutarlo).");

        $this->line($cmd, "soul execute <program>",
            "Ejecuta un pipeline guardado (.sprog) creado con soul program.");

        $this->line($cmd, "soul program \"nombre\" \"A > B > C\"",
            "Crea un pipeline Soul (.sprog) que encadena scripts.");

        $this->line($cmd, "soul programs", 
            "Lista los pipelines creados y su contenido.");

        $this->line($cmd, "soul library [carpeta]",
            "Muestra la librería de scripts incluidos, con descripciones.");

        $this->line($cmd, "soul --dump-ir",
            "Genera el IR sin compilar C.");

        $this->line($cmd, "soul --dump-c",
            "Genera el fichero C pero no compila a binario.");

        $this->line($cmd, "soul --trace",
            "Ejecuta el IR paso a paso, mostrando registros y variables.");


        echo "\n" . $desc . "Ayuda:" . $reset . "\n\n";

        $this->line($cmd, "soul ?", 
            "Muestra esta pantalla de ayuda.");

        echo "\n";
    }

    private function line(string $cmdColor, string $left, string $right): void
    {
        $reset = "\033[0m";
        echo "  " . $cmdColor . str_pad($left, 35) . $reset . " " . $right . "\n";
    }
}
