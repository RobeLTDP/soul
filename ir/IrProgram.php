<?php


final class IrProgram
{
    /** @var IrInstruction[] */
    public array $instructions = [];

    /** @var array<string,int> labelName => instructionIndex */
    public array $labels = [];

    public function add(IrInstruction $instruction): void
    {
        if ($instruction->opCode === OpCode::LABEL) {
            $labelRef = $instruction->operands[0] ?? null;
            if ($labelRef instanceof IrLabelRef) {
                $this->labels[$labelRef->name] = count($this->instructions);
            }
        }

        $this->instructions[] = $instruction;
    }

    public function dump(): string
    {
        $out = [];
        foreach ($this->instructions as $i => $inst) {
            $out[] = str_pad((string)$i, 4, ' ', STR_PAD_LEFT) . '  ' . (string)$inst;
        }
        return implode(PHP_EOL, $out) . PHP_EOL;
    }
}
