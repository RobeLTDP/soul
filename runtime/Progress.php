<?php

class Progress
{
    private ?string $action = null;
    private float $startTime = 0;
    private float $lastTick = 0;
    private int $tickIntervalMs = 1500;

    public function begin(string $action): void
    {
        $this->action = $action;
        $this->startTime = microtime(true);
        $this->lastTick = 0;

        $this->printLine("[$action] starting");
    }

    public function tick(): void
    {
        if ($this->action === null) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastTick) * 1000 < $this->tickIntervalMs) {
            return;
        }

        $this->lastTick = $now;
        $elapsed = number_format($now - $this->startTime, 2);

        $this->printLine("[$this->action] working... {$elapsed}s");
    }

    public function end(bool $ok, ?string $error = null): void
    {
        if ($this->action === null) {
            return;
        }

        $elapsed = number_format(microtime(true) - $this->startTime, 2);

        if ($ok) {
            $this->printLine("[$this->action] finished ({$elapsed}s)");
        } else {
            $this->printLine("[$this->action] error: {$error}");
        }

        $this->action = null;
    }

    private function printLine(string $text): void
    {
        //echo $text . PHP_EOL;
        fwrite(STDERR, $text . PHP_EOL);
        fflush(STDERR);
    }
}
