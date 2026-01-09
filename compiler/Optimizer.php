<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/IrInstruction.php';
require_once SOUL_ROOT . '/ir/IrOperand.php';
require_once SOUL_ROOT . '/ir/OpCode.php';

/**
 * Optimizador muy específico:
 *
 * Detecta cadenas de:
 *   CALL_BUILTIN "concat" rA rB rTmp1
 *   CALL_BUILTIN "concat" rTmp1 rC rTmp2
 *   ...
 *
 * y las reemplaza por:
 *
 *   CALL_BUILTIN "buildstr" rA rB rC ... rTmpFinal
 *
 * donde rTmpFinal es el registro resultado de la última concat.
 */
final class Optimizer
{
    public function optimize(IrProgram $p): IrProgram
    {
        $this->flattenConcatChains($p);
        return $p;
    }

    /**
     * Aplana cadenas de concat a buildstr.
     */
    private function flattenConcatChains(IrProgram $p): void
    {
        /** @var array<string,IrReg[]> $chains  regName => lista de args (IrReg) */
        $chains = [];
        $new = [];

        foreach ($p->instructions as $inst) {

            // 1) Detectamos CALL_BUILTIN "concat"
            if (
                $inst->opCode === OpCode::CALL_BUILTIN &&
                isset($inst->operands[0]) &&
                $inst->operands[0] instanceof IrLit &&
                $inst->operands[0]->value === 'concat'
            ) {
                $ops = $inst->operands;

                // Esperamos: [ IrLit("concat"), arg1Reg, arg2Reg, dstReg ]
                if (
                    count($ops) === 4 &&
                    $ops[1] instanceof IrReg &&
                    $ops[2] instanceof IrReg &&
                    $ops[3] instanceof IrReg
                ) {
                    /** @var IrReg $arg1 */
                    $arg1 = $ops[1];
                    /** @var IrReg $arg2 */
                    $arg2 = $ops[2];
                    /** @var IrReg $dst */
                    $dst  = $ops[3];

                    $dstName = $dst->name;

                    // ¿arg1 es resultado de una cadena previa?
                    if (isset($chains[$arg1->name])) {
                        // Extendemos la cadena anterior y cambiamos el registro final
                        $args = $chains[$arg1->name]; // [rA, rB, ...]
                        $args[] = $arg2;
                        unset($chains[$arg1->name]);
                        $chains[$dstName] = $args;
                    } else {
                        // Nueva cadena: concat(arg1, arg2) → dst
                        $chains[$dstName] = [$arg1, $arg2];
                    }

                    // No emitimos este concat al IR final (lo sustituiremos por buildstr)
                    continue;
                }

                // Si el patrón no encaja exactamente, no tocamos la instrucción
            }

            // 2) Cualquier otra instrucción: si usa un registro que es
            //    "resultado de cadena", insertamos buildstr antes.
            $usedRegs = [];
            foreach ($inst->operands as $op) {
                if ($op instanceof IrReg) {
                    $usedRegs[$op->name] = true;
                }
            }

            $inserted = false;

            foreach (array_keys($usedRegs) as $regName) {
                if (!isset($chains[$regName])) {
                    continue;
                }

                $args = $chains[$regName];      // lista de IrReg
                unset($chains[$regName]);

                // Creamos CALL_BUILTIN "buildstr" arg1 arg2 ... dstReg
                $dstReg = new IrReg($regName);
                $buildOperands = array_merge(
                    [new IrLit('buildstr')],
                    $args,
                    [$dstReg]
                );

                $buildInst = new IrInstruction(
                    OpCode::CALL_BUILTIN,
                    $buildOperands
                );

                $new[] = $buildInst;
                $new[] = $inst;
                $inserted = true;
                break;  // solo insertamos una vez por instrucción
            }

            if ($inserted) {
                continue;
            }

            // 3) Instrucción normal que no usa cadenas optimizables
            $new[] = $inst;
        }

        // 4) Cadenas que nunca llegaron a usarse (resultado no consumido).
        //    Por seguridad, generamos igualmente un buildstr para cada una,
        //    aunque en la práctica su valor puede quedar muerto.
        foreach ($chains as $regName => $args) {
            $dstReg = new IrReg($regName);
            $buildOperands = array_merge(
                [new IrLit('buildstr')],
                $args,
                [$dstReg]
            );

            $new[] = new IrInstruction(
                OpCode::CALL_BUILTIN,
                $buildOperands
            );
        }

        $p->instructions = $new;
    }
}
