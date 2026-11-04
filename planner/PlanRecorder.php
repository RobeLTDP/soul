<?php

final class PlanRecorder
{
    private array $actions = [];

    private array $counts = [];

    private int $readBytes  = 0;
    private int $writeBytes = 0;

    private int $filesTouched = 0;

    /** @var array<string,int> builtins que el plan no sabe simular */
    private array $unsupported = [];

    /* ----------------------------------------------------- */
    /* RECORD                                                */
    /* ----------------------------------------------------- */

    public function recordUnsupported(string $builtin): void
    {
        $this->unsupported[$builtin] = ($this->unsupported[$builtin] ?? 0) + 1;
    }

    public function record(string $type, array $data = []): void
    {
        $this->actions[] = [$type, $data];

        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;

        switch ($type) {

            case 'COPY':
                $size = $data['size'] ?? 0;
                $this->readBytes  += $size;
                $this->writeBytes += $size;
                $this->filesTouched++;
                break;

            case 'WRITE':
                $size = $data['bytes'] ?? 0;
                $this->writeBytes += $size;
                break;

            case 'DELETE':
                $this->filesTouched++;
                break;

            case 'DELETE_DIR':
                $this->readBytes += $data['bytes'] ?? 0;
                $this->filesTouched += $data['files'] ?? 0;
                break;

            case 'SCAN_DIR':
                // scanning implica lectura de metadata
                break;
        }
    }

    /* ----------------------------------------------------- */
    /* SUMMARY PRINT                                         */
    /* ----------------------------------------------------- */

    public function printSummary(int $timeline = 5): void
    {
        echo "\nPLAN SUMMARY\n";
        echo "────────────\n";

        foreach ($this->counts as $type => $count) {

            $extra = "";

            if ($type === 'DELETE_DIR') {
                foreach ($this->actions as [$t, $d]) {
                    if ($t === 'DELETE_DIR') {
                        $extra = sprintf(
                            "   %s files (%s)",
                            number_format($d['files'] ?? 0),
                            $this->fmtBytes($d['bytes'] ?? 0)
                        );
                        break;
                    }
                }
            }

            printf("%-12s %8d%s\n", $type, $count, $extra);
        }

        echo "\nEstimated IO\n";
        echo "  Read:  " . $this->fmtBytes($this->readBytes) . "\n";
        echo "  Write: " . $this->fmtBytes($this->writeBytes) . "\n";
        echo "  Ops:   " . count($this->actions) . "\n";

        echo "\nRisk level: " . $this->riskLevel() . "\n";

        if ($this->unsupported) {
            echo "\nWARNING: this plan is INCOMPLETE. Plan mode cannot simulate the\n";
            echo "builtins below, so the real run may do more than is listed above:\n";
            foreach ($this->unsupported as $name => $n) {
                echo "  - $name" . ($n > 1 ? " (x$n)" : "") . "\n";
            }
        }

        echo "\nTimeline (first $timeline)\n";

        foreach (array_slice($this->actions, 0, $timeline) as [$t, $d]) {
            echo "  $t " . json_encode($d) . "\n";
        }

        echo "\n";
    }

    /* ----------------------------------------------------- */
    /* HELPERS                                               */
    /* ----------------------------------------------------- */

    private function riskLevel(): string
    {
        if (isset($this->counts['DELETE']) || isset($this->counts['DELETE_DIR'])) {
            return 'HIGH (contains DELETE)';
        }

        if ($this->unsupported) {
            return 'UNKNOWN (plan cannot simulate: ' . implode(', ', array_keys($this->unsupported)) . ')';
        }

        if (isset($this->counts['COPY']) || isset($this->counts['WRITE'])) {
            return 'MEDIUM (writes data)';
        }

        return 'LOW (read only)';
    }

    private function fmtBytes(int $b): string
    {
        if ($b > 1_000_000_000) return round($b/1_000_000_000,2).' GB';
        if ($b > 1_000_000) return round($b/1_000_000,2).' MB';
        if ($b > 1_000) return round($b/1_000,2).' KB';
        return $b.' B';
    }
}
