<?php


final class IrInstruction
{
    /**
     * @param IrOperand[] $operands
     */
    public string $opCode;
    public array $operands = [];
    public ?IrTemp $result;

    public function __construct(string $opCode, array $operands = [], ?IrTemp $result = null)
    {
        $this->opCode = $opCode;
        $this->operands = $operands;
        $this->result = $result;
    }    

    public function __toString(): string
    {
        $parts = [];
        foreach ($this->operands as $op) {
            if ($op instanceof IrReg) {
                $parts[] = $op->name;
            } elseif ($op instanceof IrVar) {
                $parts[] = '$' . $op->name;
            } elseif ($op instanceof IrLabelRef) {
                $parts[] = $op->name;
            } elseif ($op instanceof IrLit) {
                $v = $op->value;
                if (is_string($v)) $parts[] = '"' . addslashes($v) . '"';
                else if (is_bool($v)) $parts[] = $v ? 'true' : 'false';
                else if ($v === null) $parts[] = 'null';
                else $parts[] = (string)$v;
            } else {
                $parts[] = get_class($op);
            }
        }

        return $this->opCode . (empty($parts) ? '' : ' ' . implode(' ', $parts));
    }

    public function usesTempChain(array $chains): bool
    {
        foreach ($this->operands as $o) {
            if ($o instanceof IrTemp && isset($chains[$o->id])) {
                return true;
            }
        }
        return false;
    }

    public function getTempOperands(): array
    {
        $out = [];
        foreach ($this->operands as $o) {
            if ($o instanceof IrTemp) {
                $out[] = $o;
            }
        }
        return $out;
    }


}
