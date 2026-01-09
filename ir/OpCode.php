<?php

final class OpCode
{
    // Data movement
    public const LOAD_CONST = 'LOAD_CONST';   // value -> dstReg
    public const LOAD_VAR   = 'LOAD_VAR';     // varName -> dstReg
    public const STORE_VAR  = 'STORE_VAR';    // srcReg -> varName
    public const LOAD_INDEX = 'LOAD_INDEX';   // arrayReg, indexReg -> dstReg
    public const STORE_INDEX= 'STORE_INDEX';  // arrayReg, indexReg, srcReg

    // Arithmetic
    public const ADD = 'ADD';
    public const SUB = 'SUB';
    public const MUL = 'MUL';
    public const DIV = 'DIV';
    public const MOD = 'MOD';

    // Comparisons
    public const CMP_LT = 'CMP_LT';
    public const CMP_LE = 'CMP_LE';
    public const CMP_GT = 'CMP_GT';
    public const CMP_GE = 'CMP_GE';
    public const CMP_EQ = 'CMP_EQ';
    public const CMP_NE = 'CMP_NE';

    // Unary
    public const NEG = 'NEG';
    public const NOT = 'NOT';

    // Control flow
    public const LABEL        = 'LABEL';
    public const JMP          = 'JMP';
    public const JMP_IF_FALSE = 'JMP_IF_FALSE';

    // Calls
    public const CALL_BUILTIN = 'CALL_BUILTIN';

    // Program
    public const EXIT = 'EXIT';

    const MOV = 'MOV';

    
}