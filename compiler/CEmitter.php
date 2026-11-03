<?php

require_once SOUL_ROOT . '/ir/IrProgram.php';
require_once SOUL_ROOT . '/ir/IrInstruction.php';
require_once SOUL_ROOT . '/ir/IrOperand.php';
require_once SOUL_ROOT . '/ir/OpCode.php';


final class CEmitter
{
    /**
     * Genera código C compilable.
     *
     * @param IrProgram $program
     * @param string $functionName  Nombre del entrypoint. Por defecto main.
     */
    public function emit(IrProgram $program, string $functionName = 'main'): string
    {
        // Recolectar registros usados (r0, r1...) y variables ($i, etc.)
        [$regs, $vars] = $this->collectSymbols($program);        

        $out = [];
        $out[] = '#include <stdio.h>';
        $out[] = '#include <stdint.h>';
        $out[] = '#include <inttypes.h>';
        $out[] = '#include <stddef.h>';
        $out[] = '#include <unistd.h>';
        $out[] = '#include <sys/syscall.h>';
        $out[] = '#include <fcntl.h>';        
        $out[] = '#include <errno.h>';
        $out[] = '#include <sys/socket.h>';
        $out[] = '#include <netinet/in.h>';
        $out[] = '#include <string.h>';
        $out[] = '#include <stdlib.h>';
        $out[] = '#include <sys/file.h>';
        $out[] = '#include <time.h>';
        $out[] = '#include <sys/mman.h>';
        
        
        $usesRuntime = $this->programUsesRuntimePrelude($program);

        if ($usesRuntime) {
            $out = array_merge($out, $this->emitRuntimePrelude());
        }

        $out[] = 'int ' . $functionName . '(int argc, char** argv) {';

        if ($usesRuntime) {
            $out[] = '  soul_set_args(argc, argv);';
        }

        
        // Registros: palabra máquina (sirve para enteros y punteros, estilo "reg")
        $maxReg = $this->maxRegIndex($regs);
        $out[] = '  uintptr_t r[' . ($maxReg + 1) . '];';
        $out[] = '  memset(r, 0, sizeof(r));';

        // Variables: igual, palabra máquina (path/str necesitan puntero)
        foreach (array_keys($vars) as $v) {
            $out[] = '  uintptr_t ' . $this->cVar($v) . ' = 0;';
        }

        $out[] = '';

        // Emitir instrucciones (con peephole simple)
        $insts = $program->instructions;
        $cnt = count($insts);
        $usesParallel = $this->programUsesParallel($program);
        $openHandleVars = $this->collectOpenHandleVars($program);


        for ($i = 0; $i < $cnt; $i++) {
            $inst = $insts[$i];

            // --------------------------------------------------
            // Strip implicit worker guard for non-parallel programs
            // --------------------------------------------------
            if (!$usesParallel && $inst->opCode === OpCode::CALL_BUILTIN) {
                $nameOp = $inst->operands[0] ?? null;
                if ($nameOp instanceof IrLit && $nameOp->value === 'workerId') {
                    continue; // IGNORAR workerId fuera de paralelismo
                }
            }            

            // --------------------------------------------------
            // Peephole 1: LOAD_CONST + STORE_VAR
            // --------------------------------------------------
            if ($inst->opCode === OpCode::LOAD_CONST && $i + 1 < $cnt) {
                $next = $insts[$i + 1];

                if ($next->opCode === OpCode::STORE_VAR) {
                    [$lit, $dst] = $inst->operands;
                    [$src, $var] = $next->operands;

                    if (
                        $lit instanceof IrLit &&
                        $dst instanceof IrReg &&
                        $src instanceof IrReg &&
                        $var instanceof IrVar &&
                        $dst->name === $src->name
                    ) {
                        // Nota: cLiteral ya devuelve "..." para strings, pero aquí asignamos a uintptr_t:
                        // el cast se hace en emitLoadConst (ver nota más abajo).
                        if (is_string($lit->value)) {
                            $out[] = '  ' . $this->cVar($var->name) . ' = (uintptr_t)' . $this->cLiteral($lit->value) . ';';
                        } else {
                            $out[] = '  ' . $this->cVar($var->name) . ' = (uintptr_t)' . $this->cLiteral($lit->value) . ';';
                        }
                        $i++; // saltar STORE_VAR
                        continue;
                    }
                }
            }

            // --------------------------------------------------
            // Peephole 3: CMP_* + JMP_IF_FALSE
            // --------------------------------------------------
            if (
                $i + 1 < $cnt &&
                in_array($inst->opCode, [
                    OpCode::CMP_LT,
                    OpCode::CMP_LE,
                    OpCode::CMP_GT,
                    OpCode::CMP_GE,
                    OpCode::CMP_EQ,
                    OpCode::CMP_NE,
                ], true)
            ) {
                $next = $insts[$i + 1];

                if ($next->opCode === OpCode::JMP_IF_FALSE) {
                    [$a, $b, $rCond] = $inst->operands;
                    [$rJump, $label] = $next->operands;

                    if (
                        $a instanceof IrReg &&
                        $b instanceof IrReg &&
                        $rCond instanceof IrReg &&
                        $rJump instanceof IrReg &&
                        $label instanceof IrLabelRef &&
                        $rCond->name === $rJump->name
                    ) {
                        $ai = $this->regIndex($a);
                        $bi = $this->regIndex($b);

                        $cOp = match ($inst->opCode) {
                            OpCode::CMP_LT => '<',
                            OpCode::CMP_LE => '<=',
                            OpCode::CMP_GT => '>',
                            OpCode::CMP_GE => '>=',
                            OpCode::CMP_EQ => '==',
                            OpCode::CMP_NE => '!=',
                        };

                        // Comparación de "palabra" como entero con signo (para bucles tipo i < N)
                        $out[] = "  if (!(((int64_t)r[$ai]) $cOp ((int64_t)r[$bi]))) goto {$label->name};";

                        $i++; // saltar JMP_IF_FALSE
                        continue;
                    }
                }
            }

            // --------------------------------------------------
            // Peephole 2: LOAD_VAR + LOAD_CONST + CMP_*
            // --------------------------------------------------
            if ($inst->opCode === OpCode::LOAD_VAR && $i + 2 < $cnt) {
                $inst2 = $insts[$i + 1];
                $inst3 = $insts[$i + 2];

                if (
                    $inst2->opCode === OpCode::LOAD_CONST &&
                    in_array($inst3->opCode, [
                        OpCode::CMP_LT,
                        OpCode::CMP_LE,
                        OpCode::CMP_GT,
                        OpCode::CMP_GE,
                        OpCode::CMP_EQ,
                        OpCode::CMP_NE,
                    ], true)
                ) {
                    [$var, $rX] = $inst->operands;
                    [$lit, $rY] = $inst2->operands;
                    [$a, $b, $dst] = $inst3->operands;

                    if (
                        $var instanceof IrVar &&
                        $rX instanceof IrReg &&
                        $lit instanceof IrLit &&
                        $rY instanceof IrReg &&
                        $a instanceof IrReg &&
                        $b instanceof IrReg &&
                        $dst instanceof IrReg &&
                        $rX->name === $a->name &&
                        $rY->name === $b->name &&
                        (is_int($lit->value) || is_bool($lit->value) || is_float($lit->value))
                    ) {
                        $cVar = $this->cVar($var->name);
                        $cLit = $this->cLiteral($lit->value);
                        $di   = $this->regIndex($dst);

                        $cOp = match ($inst3->opCode) {
                            OpCode::CMP_LT => '<',
                            OpCode::CMP_LE => '<=',
                            OpCode::CMP_GT => '>',
                            OpCode::CMP_GE => '>=',
                            OpCode::CMP_EQ => '==',
                            OpCode::CMP_NE => '!=',
                        };

                        $out[] = "  r[$di] = (((int64_t)$cVar) $cOp ((int64_t)$cLit)) ? 1 : 0;";

                        $i += 2;
                        continue;
                    }

                }
            }

            // --------------------------------------------------
            // Peephole 3: i = i + 1
            // --------------------------------------------------
            if ($inst->opCode === OpCode::LOAD_VAR && $i + 3 < $cnt) {
                $inst2 = $insts[$i + 1];
                $inst3 = $insts[$i + 2];
                $inst4 = $insts[$i + 3];

                if (
                    $inst2->opCode === OpCode::LOAD_CONST &&
                    $inst3->opCode === OpCode::ADD &&
                    $inst4->opCode === OpCode::STORE_VAR
                ) {
                    [$var, $rA] = $inst->operands;
                    [$lit, $rB] = $inst2->operands;
                    [$a, $b, $rC] = $inst3->operands;
                    [$src, $dstVar] = $inst4->operands;

                    if (
                        $var instanceof IrVar &&
                        $dstVar instanceof IrVar &&
                        $rA instanceof IrReg &&
                        $rB instanceof IrReg &&
                        $rC instanceof IrReg &&
                        $a instanceof IrReg &&
                        $b instanceof IrReg &&
                        $src instanceof IrReg &&
                        $var->name === $dstVar->name &&
                        $lit instanceof IrLit &&
                        $lit->value === 1 &&
                        $a->name === $rA->name &&
                        $b->name === $rB->name &&
                        $src->name === $rC->name
                    ) {
                        $cVar = $this->cVar($var->name);
                        $out[] = "  $cVar = (uintptr_t)(((int64_t)$cVar) + 1);";
                        $i += 3;
                        continue;
                    }
                }
            }

            // Fallback normal
            //$line = $this->emitInstruction($program, $inst);
            $line = $this->emitInstruction($program, $inst, $i, $openHandleVars);

            // El emisor señala llamadas mal formadas devolviendo un comentario
            // C "/* ERROR ... */". Antes el binario se generaba igualmente y la
            // llamada simplemente no hacía nada; ahora es un error de compilación.
            if (str_contains($line, '/* ERROR') || str_contains($line, '/* TODO unsupported opcode')) {
                throw new RuntimeException(
                    "CEmitter: instrucción #$i ({$inst->opCode}) no se puede compilar: " . trim($line)
                );
            }

            if ($line !== '') {
                $out[] = $line;
            }
        }
        
        $out[] = '}';
        $out[] = '';



        return implode("\n", array_filter($out, fn($l) => $l !== null));
    }

    /**
     * Guarda un .c en disco.
     */
    public function emitToFile(IrProgram $program, string $path): void
    {
        $c = $this->emit($program);
        file_put_contents($path, $c);
    }

    /**
     * Runtime C embebido (helpers/builtins) que deben ir ANTES de main().
     * Por ahora solo incluye listdir + iteración con getdents64.
     *
     * Nota: es intencionalmente "static" para que no exporte símbolos.
     */

    private function emitRuntimePrelude(): array
    {
        return [
        '',
        '// --------------------------------------------------',
        '// Soul runtime prelude (MUST appear before main)',
        '// --------------------------------------------------',
        '#include <sys/stat.h>',
        '#include <stdarg.h>',
        '#include <stdio.h>',
        '
        // --------------------------------------------------
        // Fast hash (xxHash64 minimal streaming)
        // --------------------------------------------------

        #include <stdint.h>

        typedef struct {
            uint64_t h;
        } soul_hash64_t;

        static inline void soul_hash64_init(soul_hash64_t* s) {
            s->h = 1469598103934665603ULL; // FNV offset (simple, muy rápido)
        }

        static inline void soul_hash64_update(soul_hash64_t* s, const void* data, size_t len) {
            const unsigned char* p = (const unsigned char*)data;
            uint64_t h = s->h;

            for (size_t i = 0; i < len; i++) {
                h ^= p[i];
                h *= 1099511628211ULL;
            }

            s->h = h;
        }

        static inline uint64_t soul_hash64_final(soul_hash64_t* s) {
            return s->h;
        }        
        ',
        '',
        '// --------------------------------------------------',
        '// STDIO buffering fix (real-time output for all Soul programs)',
        '// --------------------------------------------------',
        '__attribute__((constructor))',
        'static void soul_stdio_init(void) {',
        '    // stdout: flush on newline',
        '    setvbuf(stdout, NULL, _IOLBF, 0);',
        '',
        '    // stderr: no buffering (progress / debug always instant)',
        '    setvbuf(stderr, NULL, _IONBF, 0);',
        '}',
        '',

        '',
        '// --------------------------------------------------',
        '// Filesystem overview',
        '// --------------------------------------------------',
        '',
        '#define SOUL_OV_MAX_EXT 8',
        '#define SOUL_OV_EXT_LEN 16',
        '#define SOUL_TAG_INT(x) ((uintptr_t)((((int64_t)(x)) << 1) | 1))',
        '',
        'typedef struct {',
        '    int64_t files;',
        '    int64_t folders;',
        '    int64_t size;',
        '',
        '    int32_t extCount;',
        '    int32_t extTop;',
        '',
        '    char    extNames[SOUL_OV_MAX_EXT][SOUL_OV_EXT_LEN];',
        '    int64_t extFiles[SOUL_OV_MAX_EXT];',
        '    int64_t extSize[SOUL_OV_MAX_EXT];',
        '} soul_overview_t;',
        '',
        '// --------------------------------------------------',
        '// Runtime progress (stderr heartbeat)',
        '// --------------------------------------------------',
        '',
        'static const char* soul_progress_action = 0;',
        'static uint64_t soul_progress_start_ms = 0;',
        'static uint64_t soul_progress_last_ms  = 0;',
        'static uint64_t soul_progress_interval_ms = 1500;',
        '',
        'static uint64_t soul_now_ms(void) {',
        '    struct timespec ts;',
        '    clock_gettime(CLOCK_MONOTONIC, &ts);',
        '    return (uint64_t)ts.tv_sec * 1000ULL + (uint64_t)(ts.tv_nsec / 1000000ULL);',
        '}',
        '',
        'static void soul_progress_begin(const char* action) {',
        '    if (!action) action = "work";',
        '    soul_progress_action = action;',
        '    soul_progress_start_ms = soul_now_ms();',
        '    soul_progress_last_ms = 0;',
        '',
        '    // stderr (fd=2)',
        '    dprintf(2, "[%s] starting\n", soul_progress_action);',
        '}',
        '',
        'static void soul_progress_tick(void) {',
        '    if (!soul_progress_action) return;',
        '',
        '    uint64_t now = soul_now_ms();',
        '    uint64_t elapsed = now - soul_progress_start_ms;',
        '',
        '    // intervalo dinámico según duración',
        '    uint64_t interval = soul_progress_interval_ms;',
        '',
        '    if (elapsed > 10 * 1000) interval = 3000;        // >10s',
        '    if (elapsed > 60 * 1000) interval = 10000;        // >1min',
        '    if (elapsed > 5 * 60 * 1000) interval = 20000;   // >5min',
        '    if (elapsed > 8 * 60 * 1000) interval = 30000;   // >8min',
        '',
        '    if (soul_progress_last_ms != 0 &&',
        '        (now - soul_progress_last_ms) < interval) {',
        '        return;',
        '    }',
        '',
        '    soul_progress_last_ms = now;',
        '',
        '    dprintf(2, "[%s] working... %" PRIu64 "ms\\n", soul_progress_action, elapsed);',
        '}',

        '',
        'static void soul_progress_end(int ok) {',
        '    if (!soul_progress_action) return;',
        '',
        '    uint64_t now = soul_now_ms();',
        '    uint64_t elapsed = now - soul_progress_start_ms;',
        '',
        '    if (ok) {',
        '        dprintf(2, "[%s] finished (%" PRIu64 "ms)\n", soul_progress_action, elapsed);',
        '    } else {',
        '        dprintf(2, "[%s] error (%" PRIu64 "ms)\n", soul_progress_action, elapsed);',
        '    }',
        '',
        '    soul_progress_action = 0;',
        '}',        
        '#define MAX_STAT_SLOTS 32',
        '',
        'typedef struct {',
        '    int used;',
        '    struct stat st;',
        '} soul_stat_slot;',
        '',
        '#define MAX_DIRENT_NAME 256',
        '',
        'static char dir_name_bufs[16][MAX_DIRENT_NAME];',
        'static int dir_name_idx = 0;',
        '',        
        'static soul_stat_slot stat_slots[MAX_STAT_SLOTS];',
        '',
            'struct linux_dirent64 {',
            '    uint64_t        d_ino;',
            '    int64_t         d_off;',
            '    unsigned short  d_reclen;',
            '    unsigned char   d_type;',
            '    char            d_name[];',
            '   };',
            'typedef struct {',
            '  int fd;',
            '  int nread;',
            '  int bpos;',
            '  int finished; ',
            '  char buf[1 << 16];',
            '} dir_iter_t;',
            '',
            'static dir_iter_t iters[8];',
            '',
            'static uintptr_t soul_listdir(const char* path) {',
            '  static int inited = 0;',
            '  if (!inited) {',
            '    for (int i = 0; i < 8; i++) iters[i].fd = -1;',
            '    inited = 1;',
            '  }',
            '',
            '  for (int i = 0; i < 8; i++) {',
            '    if (iters[i].fd < 0) {',
            '      int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDONLY | O_DIRECTORY, 0);',
            '      if (fd < 0) return (uintptr_t)0;',
            '      iters[i].fd = fd;',
            '      iters[i].nread = 0;',
            '      iters[i].bpos = 0;',
            '      iters[i].finished = 0;',
            '      return (uintptr_t)(i + 1);',
            '    }',
            '  }',
            '  return (uintptr_t)0;',
            '}',
            '',
        '',
        '// --------------------------------------------------',
        '// analyze: single-pass heavy filesystem aggregation',
        '// --------------------------------------------------',
        '',
        '#define SOUL_AN_MAX_EXT 32',
        '#define SOUL_AN_EXT_LEN 24',
        '#define SOUL_AN_MAX_TOP 128',
        '',
        'typedef struct {',
        '    int64_t files;',
        '    int64_t dirs;',
        '    int64_t size;',
        '',
        '    int32_t extTop;',
        '    char    extNames[SOUL_AN_MAX_EXT][SOUL_AN_EXT_LEN];',
        '    int64_t extFiles[SOUL_AN_MAX_EXT];',
        '    int64_t extSize[SOUL_AN_MAX_EXT];',
        '',
        '    int32_t topN;',
        '    const char* topPath[SOUL_AN_MAX_TOP];',
        '    int64_t     topSize[SOUL_AN_MAX_TOP];',
        '',
        '} soul_analyze_t;',
        '',
        '// compound extension (.tar.gz etc)',
        'static const char* soul_ext_compound(const char* name) {',
        '    const char* last = NULL;',
        '    const char* prev = NULL;',
        '    const char* p = name;',
        '',
        '    while (*p) {',
        '        if (*p == \'.\') {',
        '            prev = last;',
        '            last = p;',
        '        }',
        '        p++;',
        '    }',
        '',
        '    if (!last) return NULL;',
        '',
        '    if (prev && (last - prev) <= 5) {',
        '        return prev + 1;',
        '    }',
        '',
        '    return last + 1;',
        '}',
        '',
        '',
        '// -------- analyze getters --------',
        '',
        'static int64_t soul_an_files(uintptr_t h) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    return a->files;',
        '}',
        '',
        'static int64_t soul_an_dirs(uintptr_t h) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    return a->dirs;',
        '}',
        '',
        'static int64_t soul_an_size(uintptr_t h) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    return a->size;',
        '}',
        '',
        'static int64_t soul_an_ext_count(uintptr_t h) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    return a->extTop;',
        '}',
        '',
        'static const char* soul_an_ext_name(uintptr_t h, int64_t i) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    if (i < 0 || i >= a->extTop) return "";',
        '    return a->extNames[i];',
        '}',
        '',
        'static int64_t soul_an_ext_files(uintptr_t h, int64_t i) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    if (i < 0 || i >= a->extTop) return 0;',
        '    return a->extFiles[i];',
        '}',
        '',
        'static int64_t soul_an_ext_size(uintptr_t h, int64_t i) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    if (i < 0 || i >= a->extTop) return 0;',
        '    return a->extSize[i];',
        '}',
        '',
        'static int64_t soul_an_top_count(uintptr_t h) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    return a->topN;',
        '}',
        '',
        'static const char* soul_an_top_path(uintptr_t h, int64_t i) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    if (i < 0 || i >= a->topN) return "";',
        '    return a->topPath[i] ? a->topPath[i] : "";',
        '}',
        '',
        'static int64_t soul_an_top_size(uintptr_t h, int64_t i) {',
        '    soul_analyze_t* a = (soul_analyze_t*)h;',
        '    if (i < 0 || i >= a->topN) return 0;',
        '    return a->topSize[i];',
        '}',
        '',
        '// forward decls (needed by C)',
        'static const char* soul_persist_str(const char* s);',
        '',
        'static void soul_an_ext_add(soul_analyze_t* a, const char* ext, int64_t size) {',
        '    if (!ext) return;',
        '',
        '    for (int i = 0; i < a->extTop; i++) {',
        '        if (strncmp(a->extNames[i], ext, SOUL_AN_EXT_LEN) == 0) {',
        '            a->extFiles[i]++;',
        '            a->extSize[i] += size;',
        '            return;',
        '        }',
        '    }',
        '',
        '    if (a->extTop < SOUL_AN_MAX_EXT) {',
        '        int i = a->extTop++;',
        '        strncpy(a->extNames[i], ext, SOUL_AN_EXT_LEN-1);',
        '        a->extNames[i][SOUL_AN_EXT_LEN-1] = 0;',
        '        a->extFiles[i] = 1;',
        '        a->extSize[i]  = size;',
        '    }',
        '}',
        '',
        'static void soul_an_top_add(soul_analyze_t* a, const char* path, int64_t size) {',
        '    if (a->topN <= 0) return;',
        '',
        '    int minIdx = 0;',
        '    for (int i = 1; i < a->topN; i++) {',
        '        if (a->topSize[i] < a->topSize[minIdx]) minIdx = i;',
        '    }',
        '',
        '    if (size <= a->topSize[minIdx]) return;',
        '',
        '    a->topSize[minIdx] = size;',
        '    a->topPath[minIdx] = soul_persist_str(path);',
        '}',
        '',
        'static void soul_an_scan(const char* path, soul_analyze_t* a) {',
        '',
        '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDONLY | O_DIRECTORY, 0);',
        '',
        '    // --------------------------------------------------',
        '    // Si NO es directorio → es archivo regular',
        '    // --------------------------------------------------',
        '    if (fd < 0) {',
        '        struct stat st;',
        '        if (lstat(path, &st) != 0) return;',
        '',
        '        if (S_ISREG(st.st_mode)) {',
        '            a->files++;',
        '            a->size += st.st_size;',
        '',
        '            const char* ext = soul_ext_compound(path);',
        '            soul_an_ext_add(a, ext, st.st_size);',
        '            soul_an_top_add(a, path, st.st_size);',
        '        }',
        '        return;',
        '    }',
        '',
        '    // --------------------------------------------------',
        '    // Directorio → iterar sin lstat por entrada',
        '    // --------------------------------------------------',
        '',
        '    a->dirs++;',
        '',
        '    char buf[1 << 16];',
        '',
        '    for (;;) {',
        '        int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
        '        if (nread <= 0) break;',
        '',
        '        int bpos = 0;',
        '        while (bpos < nread) {',
        '',
        '            struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
        '            bpos += d->d_reclen;',
        '',
        '            // skip "." y ".."',
        '            if (d->d_name[0] == 46) {',
        '                if (d->d_name[1] == 0) continue;',
        '                if (d->d_name[1] == 46 && d->d_name[2] == 0) continue;',
        '            }',
        '',
        '            char child[4096];',
        '            snprintf(child, sizeof(child), "%s/%s", path, d->d_name);',
        '',
        '            // --------------------------------------------------',
        '            // USAR d_type → evita lstat masivo',
        '            // --------------------------------------------------',
        '',
        '            #ifndef DT_DIR',
        '            #define DT_DIR 4',
        '            #endif',
        '',
        '            #ifndef DT_REG',
        '            #define DT_REG 8',
        '            #endif',
        '',
        '            if (d->d_type == DT_DIR) {',
        '                soul_an_scan(child, a);',
        '                continue;',
        '            }',
        '',
        '            if (d->d_type == DT_REG) {',
        '                struct stat st;',
        '                if (lstat(child, &st) == 0) {',
        '                    a->files++;',
        '                    a->size += st.st_size;',
        '',
        '                    const char* ext = soul_ext_compound(child);',
        '                    soul_an_ext_add(a, ext, st.st_size);',
        '                    soul_an_top_add(a, child, st.st_size);',
        '                }',
        '                continue;',
        '            }',
        '',
        '            // fallback (symlink u otros)',
        '            soul_an_scan(child, a);',
        '        }',
        '    }',
        '',
        '    syscall(SYS_close, fd);',
        '}',

        '',
        'static soul_analyze_t soul_an_pool[4];',
        'static int soul_an_idx = 0;',
        '',
        'static uintptr_t soul_analyze(const char* path, int64_t topN) {',
        '',
        '    soul_analyze_t* a = &soul_an_pool[soul_an_idx++ & 3];',
        '    memset(a, 0, sizeof(*a));',
        '',
        '    if (topN < 0) topN = 0;',
        '    if (topN > SOUL_AN_MAX_TOP) topN = SOUL_AN_MAX_TOP;',
        '',
        '    a->topN = (int)topN;',
        '',
        '    soul_an_scan(path, a);',
        '',
        '    return (uintptr_t)a;',
        '}',
        '',

            'typedef struct {
                int fd;
                int nread;
                int bpos;
                int finished;

                char buf[1 << 16];

                // stack de directorios pendientes
                char stack[256][4096];
                int stackSize;

                // path actual
                char currentPath[4096];
            } walk_iter_t;',
            '#define MAX_WALK_ITERS 4

            static walk_iter_t walk_iters[MAX_WALK_ITERS];
                        
            __attribute__((constructor))
            static void soul_walk_init(void) {
                for (int i = 0; i < MAX_WALK_ITERS; i++) {
                    walk_iters[i].fd = -1;
                }
            }
            
            static uintptr_t soul_walk(const char* path) {
                if (!path || !path[0]) return 0;

                for (int i = 0; i < MAX_WALK_ITERS; i++) {
                    if (walk_iters[i].fd < 0) {

                        memset(&walk_iters[i], 0, sizeof(walk_iter_t));

                        int fd = (int)syscall(
                            SYS_openat,
                            AT_FDCWD,
                            path,
                            O_RDONLY | O_DIRECTORY,
                            0
                        );

                        if (fd < 0) return 0;

                        walk_iters[i].fd = fd;
                        walk_iters[i].stackSize = 0;

                        // guardar path raíz
                        strncpy(walk_iters[i].currentPath, path, sizeof(walk_iters[i].currentPath) - 1);

                        return (uintptr_t)(i + 1);
                    }
                }

                return 0;
            }',
            '
            static uintptr_t soul_walk_next(uintptr_t h) {
                if (!h) return 0;

                int idx = (int)h - 1;
                if (idx < 0 || idx >= MAX_WALK_ITERS) return 0;

                walk_iter_t* it = &walk_iters[idx];
                if (it->fd < 0) return 0;

                static char outBufs[8][4096];
                static int outIdx = 0;
                char* out = outBufs[(outIdx++) & 7];

                for (;;) {

                    if (it->bpos >= it->nread) {
                        it->nread = (int)syscall(
                            SYS_getdents64,
                            it->fd,
                            it->buf,
                            sizeof(it->buf)
                        );
                        it->bpos = 0;

                        if (it->nread <= 0) {
                            syscall(SYS_close, it->fd);
                            it->fd = 0;

                            if (it->stackSize == 0) {
                                return 0;
                            }

                            it->stackSize--;
                            strncpy(it->currentPath,
                                    it->stack[it->stackSize],
                                    sizeof(it->currentPath) - 1);
                            it->currentPath[sizeof(it->currentPath) - 1] = 0;

                            it->fd = (int)syscall(
                                SYS_openat,
                                AT_FDCWD,
                                it->currentPath,
                                O_RDONLY | O_DIRECTORY,
                                0
                            );

                            it->nread = 0;
                            it->bpos = 0;
                            continue;
                        }
                    }

                    struct linux_dirent64* d =
                        (struct linux_dirent64*)(it->buf + it->bpos);

                    it->bpos += d->d_reclen;',        
                    'if (d->d_name[0] == 46) {',
                    '    if (d->d_name[1] == 0) continue;',
                    '    if (d->d_name[1] == 46 && d->d_name[2] == 0) continue;',
                    '}',
                    '',
                    '// usar d_type en vez de lstat (0 syscalls)',
                    '
                    // construir path completo
                    snprintf(out, 4096, "%s/%s", it->currentPath, d->d_name);

                    // si es directorio → push al stack
                    #ifndef DT_DIR
                    #define DT_DIR 4
                    #endif

                    if (it->stackSize < 256 && d->d_type == DT_DIR) {
                        strncpy(it->stack[it->stackSize], out, 4095);
                        it->stack[it->stackSize][4095] = 0;
                        it->stackSize++;
                    }

                    return (uintptr_t)out;


                }
            }
                        
            ',

            'static void soul_walk_close(uintptr_t h) {
                if (!h) return;

                int idx = (int)h - 1;
                if (idx < 0 || idx >= MAX_WALK_ITERS) return;

                walk_iter_t* it = &walk_iters[idx];

                if (it->fd > 0) {
                    syscall(SYS_close, it->fd);
                }

                memset(it, 0, sizeof(walk_iter_t));
                it->fd = -1;
            }',


            '// --------------------------------------------------',
            '// CLI arguments (argc / argv)',
            '// --------------------------------------------------',
            '',
            'static int soul_argc = 0;',
            'static char** soul_argv = 0;',
            '',
            'static void soul_set_args(int argc, char** argv) {',
            '   soul_argc = argc;',
            '    soul_argv = argv;',
            '}',
            '',
            'static uintptr_t soul_argc_fn(void) {',
            '    return (uintptr_t)soul_argc;',
            '}',
            '',
            'static uintptr_t soul_argv_fn(int64_t i) {',
            '    if (i < 0 || i >= soul_argc) return (uintptr_t)0;',
            '    return (uintptr_t)soul_argv[i];',
            '}',
            '',
            'static uintptr_t soul_arg(const char* name, const char* def) {',
            '    if (!name || !soul_argv) {',
            '        return (uintptr_t)(def ? def : "");',
            '    }',
            '',
            '    for (int i = 1; i < soul_argc; i++) {',
            '        const char* a = soul_argv[i];',
            '        if (!a) continue;',
            '',
            '        // comparación manual (sin strcmp)',
            '        int j = 0;',
            '        while (name[j] && a[j] && name[j] == a[j]) j++;',
            '        if (name[j] == 0 && a[j] == 0) {',
            '            // encontrado',
            '            if (i + 1 < soul_argc) {',
            '                return (uintptr_t)soul_argv[i + 1];',
            '            }',
            '            return (uintptr_t)"";',
            '        }',
            '    }',
            '',
            '    return (uintptr_t)(def ? def : "");',
            '}',
            '',
            'static uintptr_t soul_to_int(const char* s) {',
            '    if (!s) return 0;',
            '',
            '    int neg = 0;',
            '    if (*s == \'-\') {',
            '        neg = 1;',
            '        s++;',
            '    }',
            '',
            '    int64_t val = 0;',
            '    while (*s >= \'0\' && *s <= \'9\') {',
            '        val = val * 10 + (*s - \'0\');',
            '        s++;',
            '    }',
            '',
            '    return (uintptr_t)(neg ? -val : val);',
            '}',
            '',
            'static uintptr_t soul_rmdir(const char* path) {',
            '    if (!path) return 0;',
            '    return syscall(SYS_unlinkat, AT_FDCWD, path, AT_REMOVEDIR) == 0 ? 1 : 0;',
            '}',
            '',
            'static uintptr_t soul_concat(const char* a, const char* b) {',
            '  static char bufs[16][4096];',
            '  static int bufIdx = 0;',
            '  char* buf = bufs[(bufIdx++) & 15];',
            '',
            '  if (!a) a = "";',
            '  if (!b) b = "";',
            '',
            '  int pos = 0;',
            '',
            '  for (int i = 0; a[i] && pos < 4095; i++) buf[pos++] = a[i];',
            '  for (int i = 0; b[i] && pos < 4095; i++) buf[pos++] = b[i];',
            '',
            '  buf[pos] = 0;',
            '  return (uintptr_t)buf;',
            '}',
            '',
            'static uintptr_t soul_path_join(const char* a, const char* b) {',
            '  static char bufs[16][4096];',
            '  static int bufIdx = 0;',
            '  char* buf = bufs[(bufIdx++) & 15];',
            '',
            '  if (!a) a = "";',
            '  if (!b) b = "";',
            '',
            '  int pos = 0;',
            '',
            '  for (int i = 0; a[i] && pos < 4095; i++) buf[pos++] = a[i];',
            '',
            '  // añadir \'/\' solo para paths',
            '  if (pos > 0 && buf[pos - 1] != \'/\' && b[0] != \'/\' && pos < 4095) buf[pos++] = \'/\';',
            '',
            '  for (int i = 0; b[i] && pos < 4095; i++) buf[pos++] = b[i];',
            '',
            '  buf[pos] = 0;',
            '  return (uintptr_t)buf;',
            '}',
            '',
            'static uintptr_t soul_int_to_str(int64_t v) {',
            '  static char bufs[16][64];',
            '  static int bufIdx = 0;',
            '  char* out = bufs[(bufIdx++) & 15];',
            '',
            '  // conversión manual (sin snprintf)',
            '  int neg = (v < 0);',
            '  uint64_t x = neg ? (uint64_t)(-(v + 1)) + 1 : (uint64_t)v;',
            '',
            '  char tmp[32];',
            '  int n = 0;',
            '  do {',
            '    tmp[n++] = (char)(48 + (x % 10));',
            '    x /= 10;',
            '  } while (x && n < 31);',
            '',
            '  int pos = 0;',
            '  if (neg) out[pos++] = \'-\';',
            '',
            '  while (n > 0 && pos < 63) out[pos++] = tmp[--n];',
            '',
            '  out[pos] = 0;',
            '  return (uintptr_t)out;',
            '}',
            '',

            
            'static uintptr_t soul_endswith(const char* text, const char* suffix) {',
            '  if (!text || !suffix) return 0;',
            '',
            '  int lt = 0;',
            '  while (text[lt]) lt++;',
            '',
            '  int ls = 0;',
            '  while (suffix[ls]) ls++;',
            '',
            '  if (ls > lt) return 0;',
            '',
            '  for (int i = 0; i < ls; i++) {',
            '    if (text[lt - ls + i] != suffix[i]) return 0;',
            '  }',
            '',
            '  return 1;',
            '}',
            '',
            'static uintptr_t soul_startswith(const char* text, const char* prefix) {',
            '  if (!text || !prefix) return 0;',
            '',
            '  int lt = 0;',
            '  while (text[lt]) lt++;',
            '',
            '  int lp = 0;',
            '  while (prefix[lp]) lp++;',
            '',
            '  if (lp > lt) return 0;',
            '',
            '  for (int i = 0; i < lp; i++) {',
            '    if (text[i] != prefix[i]) return 0;',
            '  }',
            '',
            '  return 1;',
            '}',
            '',
            'static uintptr_t soul_mtime(const char* path) {',
            '    struct stat st;',
            '    if (stat(path, &st) == 0) {',
            '        return (uintptr_t)st.st_mtime;',
            '    }',
            '    return 0;',
            '}',
            '',
            '// --------------------------------------------------',
            '// Persistent string pool (NO overwrite, NO free)',
            '// --------------------------------------------------',
            '',
            '#define SOUL_STR_POOL_SIZE (1 << 27)  // 1 MiB por proceso (ajustable)',
            '',
            'static char soul_str_pool[SOUL_STR_POOL_SIZE];',
            'static int soul_str_pool_pos = 0;',
            '',
            'static const char* soul_persist_str(const char* s) {',
            '    if (!s) s = "";',
            '',
            '    int len = 0;',
            '    while (s[len]) len++;',
            '   if (len == 0) return "";',
            '',
            '    // Si no cabe, devolvemos el string original (fallback seguro)',
            '    if (len + 1 > SOUL_STR_POOL_SIZE) return s;',
            '    if (soul_str_pool_pos + len + 1 > SOUL_STR_POOL_SIZE) return s;',
            '',
            '    char* out = soul_str_pool + soul_str_pool_pos;',
            '',
            '    for (int i = 0; i < len; i++) {',
            '        out[i] = s[i];',
            '    }',
            '    out[len] = 0;',
            '',
            '    soul_str_pool_pos += len + 1;',
            '    return out;',
            '}',
            '',            
            '
            static uintptr_t soul_buildstr_tmp(int count, uintptr_t* parts) {
                static char bufs[16][4096];
                static int bufIdx = 0;
                char* out = bufs[(bufIdx++) & 15];

                int pos = 0;
                for (int i = 0; i < count; i++) {
                    const char* s = (const char*)parts[i];
                    if (!s) continue;
                    for (int j = 0; s[j] && pos < 4095; j++) out[pos++] = s[j];
                }
                out[pos] = 0;
                return (uintptr_t)out;
            }

            static uintptr_t soul_buildstr_persist(int count, uintptr_t* parts) {
                char tmp[4096];
                int pos = 0;

                for (int i = 0; i < count; i++) {
                    const char* s = (const char*)parts[i];
                    if (!s) continue;
                    for (int j = 0; s[j] && pos < 4095; j++) tmp[pos++] = s[j];
                }
                tmp[pos] = 0;

                return (uintptr_t)soul_persist_str(tmp);
            }

            static uintptr_t soul_buildstr(int count, uintptr_t* parts) {
                return soul_buildstr_persist(count, parts);
            }

            ',
            '',
            '
            static uintptr_t soul_json_encode(int count, uintptr_t* args) {
                static char bufs[8][4096];
                static int bufIdx = 0;
                char* out = bufs[(bufIdx++) & 7];

                int pos = 0;
                out[pos++] = 123; /* { */

                for (int i = 0; i + 1 < count; i += 2) {
                    const char* key = (const char*)args[i];
                    uintptr_t val   = args[i + 1];

                    if (!key) key = "";

                    if (i > 0) {
                        out[pos++] = 44; /* , */
                    }

                    /* "key": */
                    out[pos++] = 34; /* " */
                    for (int k = 0; key[k] && pos < 4090; k++) {
                        if ((unsigned char)key[k] == 34 || (unsigned char)key[k] == 92) {
                            out[pos++] = 92; /* \ */
                        }
                        out[pos++] = key[k];
                    }
                    out[pos++] = 34; /* " */
                    out[pos++] = 58; /* : */

                    /* value */
                    if ((val & 1) == 1) {
                        int64_t n = ((int64_t)val) >> 1;
                        char tmp[64];
                        snprintf(tmp, sizeof(tmp), "%lld", (long long)n);
                        for (int k = 0; tmp[k] && pos < 4090; k++) {
                            out[pos++] = tmp[k];
                        }
                    } else {
                        const char* sval = (const char*)val;
                        if (!sval) sval = "";

                        out[pos++] = 34; /* " */
                        for (int k = 0; sval[k] && pos < 4090; k++) {
                            if ((unsigned char)sval[k] == 34 || (unsigned char)sval[k] == 92) {
                                out[pos++] = 92; /* \ */
                            }
                            out[pos++] = sval[k];
                        }
                        out[pos++] = 34; /* " */
                    }
                }

                out[pos++] = 125; /* } */
                out[pos] = 0;

                return (uintptr_t)soul_persist_str(out);
            }
            ',
            '
            static uintptr_t soul_json_pair_str(int count, uintptr_t* args) {
                const char* key = (count > 0 && args[0]) ? (const char*)args[0] : "";
                const char* val = (count > 1 && args[1]) ? (const char*)args[1] : "";

                static char bufs[8][4096];
                static int idx = 0;
                char* out = bufs[(idx++) & 7];

                int pos = 0;

                // "key":
                out[pos++] = 34;
                for (int i = 0; key[i] && pos < 4090; i++) {
                    if (key[i] == 34 || key[i] == 92) out[pos++] = 92;
                    out[pos++] = key[i];
                }
                out[pos++] = 34;
                out[pos++] = 58;

                // "value"
                out[pos++] = 34;
                for (int i = 0; val[i] && pos < 4090; i++) {
                    if (val[i] == 34 || val[i] == 92) out[pos++] = 92;
                    out[pos++] = val[i];
                }
                out[pos++] = 34;

                out[pos] = 0;
                return (uintptr_t)soul_persist_str(out);
            }
            ',
            '
            static uintptr_t soul_json_pair_int(int count, uintptr_t* args) {
                const char* key = (count > 0 && args[0]) ? (const char*)args[0] : "";
                int64_t val = (count > 1) ? (int64_t)args[1] : 0;

                static char bufs[8][4096];
                static int idx = 0;
                char* out = bufs[(idx++) & 7];

                int pos = 0;

                // "key":
                out[pos++] = 34;
                for (int i = 0; key[i] && pos < 4090; i++) {
                    if (key[i] == 34 || key[i] == 92) out[pos++] = 92;
                    out[pos++] = key[i];
                }
                out[pos++] = 34;
                out[pos++] = 58;

                // number
                char tmp[64];
                snprintf(tmp, sizeof(tmp), "%lld", (long long)val);
                for (int i = 0; tmp[i] && pos < 4090; i++) {
                    out[pos++] = tmp[i];
                }

                out[pos] = 0;
                return (uintptr_t)soul_persist_str(out);
            }

            ',
            '
            static uintptr_t soul_json_object(int count, uintptr_t* parts) {
                static char bufs[8][4096];
                static int idx = 0;
                char* out = bufs[(idx++) & 7];

                int pos = 0;
                out[pos++] = 123; /* { */

                for (int i = 0; i < count; i++) {
                    const char* p = (const char*)parts[i];
                    if (!p) continue;

                    if (i > 0) out[pos++] = 44; /* , */

                    for (int j = 0; p[j] && pos < 4090; j++) {
                        out[pos++] = p[j];
                    }
                }

                out[pos++] = 125; /* } */
                out[pos] = 0;

                return (uintptr_t)soul_persist_str(out);
            }
            ',

            'static uintptr_t soul_strlen(const char* text) {',
            '  if (!text) return 0;',
            '  uintptr_t n = 0;',
            '  while (text[n]) n++;',
            '  return n;',
            '}',
            '',
            'static uintptr_t soul_slice(const char* text, int64_t start, int64_t len) {',
            '  // Ring buffer de slices (sin malloc, sin ownership complejo)',
            '  static char bufs[16][4096];',
            '  static int bufIdx = 0;',
            '  char* out = bufs[(bufIdx++) & 15];',
            '',
            '  // devolver "" siempre válido',
            '  out[0] = 0;',
            '',
            '  if (!text) return (uintptr_t)out;',
            '  if (len <= 0) return (uintptr_t)out;',
            '',
            '  if (start < 0) start = 0;',
            '',
            '  // calcular longitud de text',
            '  int64_t lt = 0;',
            '  while (text[lt]) lt++;',
            '',
            '  if (start >= lt) return (uintptr_t)out;',
            '',
            '  // recorte de len',
            '  int64_t maxLen = lt - start;',
            '  if (len > maxLen) len = maxLen;',
            '',
            '  // limitar al buffer (4096, dejando sitio para 0)',
            '  if (len > 4095) len = 4095;',
            '',
            '  for (int64_t i = 0; i < len; i++) {',
            '    out[i] = text[start + i];',
            '  }',
            '  out[len] = 0;',
            '',
            '  return (uintptr_t)out;',
            '}',
            '',
            'static int soul_copyFolder_depth = 0;
            
            // --------------------------------------------------
            // Copy integrity control (GLOBAL STATE)
            // --------------------------------------------------
            static int soul_copy_fail_fast = 1;
            static int soul_copy_errors = 0;
            static int soul_copy_verify_enabled = 1;            
            
            ',            
            '',
            '
            static uintptr_t soul_copy_verify(const char* src, const char* dst, int verify) {
                if (!src || !dst) return 0;

                int in = (int)syscall(SYS_openat, AT_FDCWD, src, O_RDONLY, 0);
                if (in < 0) return 0;

                int out = (int)syscall(SYS_openat, AT_FDCWD, dst, O_WRONLY | O_CREAT | O_TRUNC, 0644);
                if (out < 0) {
                    syscall(SYS_close, in);
                    return 0;
                }

                soul_hash64_t hsrc;
                if (verify) {
                    soul_hash64_init(&hsrc);
                }

                char buf[1 << 16];

                for (;;) {
                    long n = syscall(SYS_read, in, buf, sizeof(buf));
                    if (n == 0) break;
                    if (n < 0) goto fail;

                    if (verify) soul_hash64_update(&hsrc, buf, (size_t)n);

                    char* p = buf;
                    long left = n;

                    while (left > 0) {
                        long w = syscall(SYS_write, out, p, (size_t)left);
                        if (w <= 0) goto fail;
                        p += w;
                        left -= w;
                    }

                    if (soul_copyFolder_depth == 1) soul_progress_tick();
                }

                syscall(SYS_close, in);
                syscall(SYS_close, out);

                if (verify) {
                    // ===== RE-READ DST FROM DISK =====
                    int fd2 = (int)syscall(SYS_openat, AT_FDCWD, dst, O_RDONLY, 0);
                    if (fd2 < 0) return 0;

                    soul_hash64_t hdst;
                    soul_hash64_init(&hdst);

                    for (;;) {
                        long n2 = syscall(SYS_read, fd2, buf, sizeof(buf));
                        if (n2 == 0) break;
                        if (n2 < 0) {
                            syscall(SYS_close, fd2);
                            return 0;
                        }
                        soul_hash64_update(&hdst, buf, (size_t)n2);
                    }

                    syscall(SYS_close, fd2);

                    uint64_t a = soul_hash64_final(&hsrc);
                    uint64_t b = soul_hash64_final(&hdst);
                    if (a != b) return 0;
                }

                return 1;

            fail:
                syscall(SYS_close, in);
                syscall(SYS_close, out);
                return 0;
            }

            ',
            '
            static uintptr_t soul_copy(const char* src, const char* dst) {
            return soul_copy_verify(src, dst, 0);
            }

            static uintptr_t soul_copy_checked(const char* src, const char* dst) {
            return soul_copy_verify(src, dst, 1);
            }
                        
            ',
            '',
            'static uintptr_t soul_removeTree(const char* path) {',
            '  if (!path || !path[0]) return 0;',
            '',
            '  struct stat st;',
            '  if (lstat(path, &st) != 0) return 0;',
            '',
            '  static int remove_depth = 0;',
            '  remove_depth++;',
            '  int is_root = (remove_depth == 1);',
            '  if (is_root) soul_progress_begin("remove");',
            '',
            '  if (S_ISDIR(st.st_mode)) {',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDONLY | O_DIRECTORY, 0);',
            '    if (fd >= 0) {',
            '      char buf[1 << 16];',
            '      for (;;) {',
            '        int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
            '        if (nread <= 0) break;',
            '',
            '        int bpos = 0;',
            '        while (bpos < nread) {',
            '          if (is_root) soul_progress_tick();',
            '',
            '          struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
            '          bpos += d->d_reclen;',
            '',
            '          if (d->d_name[0] == \'.\' &&',
            '              (d->d_name[1] == 0 ||',
            '              (d->d_name[1] == \'.\' && d->d_name[2] == 0))) {',
            '            continue;',
            '          }',
            '',
            '          char child[4096];',
            '          snprintf(child, sizeof(child), "%s/%s", path, d->d_name);',
            '          soul_removeTree(child);',
            '        }',
            '      }',
            '      syscall(SYS_close, fd);',
            '    }',
            '',
            '    syscall(SYS_unlinkat, AT_FDCWD, path, AT_REMOVEDIR);',
            '  } else {',
            '    syscall(SYS_unlinkat, AT_FDCWD, path, 0);',
            '  }',
            '',
            '  remove_depth--;                      
            ',
            '  if (is_root) soul_progress_end(1);',
            '',
            '  return 1;',
            '}',
            '',
            '
            
            ',
            '',
            'static uintptr_t soul_append(const char* path, const char* text) {',
            '    if (!path || !text) return 0;',
            '',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_WRONLY | O_CREAT | O_APPEND, 0644);',
            '',
            '    if (fd < 0) return 0;',
            '',
            '    // calcular longitud del texto',
            '    size_t len = 0;',
            '    while (text[len]) len++;',
            '',
            '    long w = syscall(SYS_write, fd, text, len);',
            '',
            '    syscall(SYS_close, fd);',
            '',
            '    return (w == (long)len) ? 1 : 0;',
            '}',
            '
            static uintptr_t soul_now(void) {
                return SOUL_TAG_INT((int64_t)soul_now_ms());
            }
            ',     
        '',
        '// --------------------------------------------------',
        '// Buffered write runtime (open / write / close)',
        '// --------------------------------------------------',
        '',
        '#define MAX_WRITE_SLOTS 8',
        '#define WRITE_BUF_SIZE 4096',
        '',
        'typedef struct {',
        '  int fd;',
        '  int used;',
        '  size_t pos;',
        '  char buf[WRITE_BUF_SIZE];',
        '} soul_write_slot;',
        '',
        'static soul_write_slot write_slots[MAX_WRITE_SLOTS];',
        '',
        'static uintptr_t soul_open(const char* path) {',
        '  if (!path) return 0;',
        '',
        '  for (int i = 0; i < MAX_WRITE_SLOTS; i++) {',
        '    if (!write_slots[i].used) {',
        '      int fd = (int)syscall(',
        '        SYS_openat,',
        '        AT_FDCWD,',
        '        path,',
        '        O_WRONLY | O_CREAT | O_TRUNC,',
        '        0644',
        '      );',
        '',
        '      if (fd < 0) return 0;',
        '',
        '      write_slots[i].fd = fd;',
        '      write_slots[i].used = 1;',
        '      write_slots[i].pos = 0;',
        '      return (uintptr_t)(i + 1);',
        '    }',
        '  }',
        '  return 0;',
        '}',
        '',
        'static void soul_write(uintptr_t h, const char* text) {',
        '  if (!text || h == 0) return;',
        '',
        '  int idx = (int)h - 1;',
        '  if (idx < 0 || idx >= MAX_WRITE_SLOTS) return;',
        '',
        '  soul_write_slot* w = &write_slots[idx];',
        '  if (!w->used) return;',
        '',
        '  for (size_t i = 0; text[i]; i++) {',
        '    w->buf[w->pos++] = text[i];',
        '',
        '    if (w->pos == WRITE_BUF_SIZE) {',
        '      syscall(SYS_write, w->fd, w->buf, w->pos);',
        '      w->pos = 0;',
        '    }',
        '  }',
        '}',
        '',
        'static void soul_close(uintptr_t h) {',
        '  if (h == 0) return;',
        '',
        '  int idx = (int)h - 1;',
        '  if (idx < 0 || idx >= MAX_WRITE_SLOTS) return;',
        '',
        '  soul_write_slot* w = &write_slots[idx];',
        '  if (!w->used) return;',
        '',
        '  if (w->pos > 0) {',
        '    syscall(SYS_write, w->fd, w->buf, w->pos);',
        '  }',
        '',
        '  syscall(SYS_close, w->fd);',
        '  w->used = 0;',
        '}',
        '',
        '// write(path, text) convenience: open + write + close',
        'static uintptr_t soul_write_file(const char* path, const char* text) {',
        '  if (!path || !text) return 0;',
        '  uintptr_t h = soul_open(path);',
        '  if (!h) return 0;',
        '  soul_write(h, text);',
        '  soul_close(h);',
        '  return 1;',
        '}',
        '',            
            
            'static uintptr_t soul_mkdir(const char* path) {',
            '  if (!path) return 0;',
            '',
            '  long r = syscall(SYS_mkdirat, AT_FDCWD, path, 0755);',
            '  if (r == 0) return 1;',
            '',
            '  // si ya existe, lo consideramos OK',
            '  if (errno == EEXIST) return 1;',
            '',
            '  return 0;',
            '}',
            '',
            'static uintptr_t soul_stat(const char* path) {',
            '    if (!path) return 0;',
            '',
            '    // buscar slot libre',
            '    for (int i = 0; i < MAX_STAT_SLOTS; i++) {',
            '        if (!stat_slots[i].used) {',
            '            if (stat(path, &stat_slots[i].st) != 0) return 0;',
            '            stat_slots[i].used = 1;',
            '            return (uintptr_t)&stat_slots[i].st;',
            '        }',
            '    }',
            '',
            '    // sin slots disponibles -> fallback a 0',
            '    return 0;',
            '}',
            '',
            'static void soul_stat_free(uintptr_t p) {',
            '    if (!p) return;',
            '    struct stat* stp = (struct stat*)p;',
            '    for (int i = 0; i < MAX_STAT_SLOTS; i++) {',
            '        if (&stat_slots[i].st == stp) {',
            '            stat_slots[i].used = 0;',
            '            return;',
            '        }',
            '    }',
            '}',
            '',
            'static uintptr_t soul_isfile(uintptr_t p) {',
            '    struct stat* st = (struct stat*)p;',
            '    if (!st) return 0;',
            '    int r = S_ISREG(st->st_mode) ? 1 : 0;',
            '    soul_stat_free(p);',
            '    return (uintptr_t)r;',
            '}',
            '',
            'static uintptr_t soul_isdir(uintptr_t p) {',
            '    struct stat* st = (struct stat*)p;',
            '    if (!st) return 0;',
            '    int r = S_ISDIR(st->st_mode) ? 1 : 0;',
            '    soul_stat_free(p);',
            '    return (uintptr_t)r;',
            '}',
            '',
            'static uintptr_t soul_dir_next(uintptr_t h) {',
            '  if (h == 0) return (uintptr_t)0;',
            '',
            '  int idx = (int)h - 1;',
            '  if (idx < 0 || idx >= 8) return (uintptr_t)0;',
            '',
            '  dir_iter_t* it = &iters[idx];',
            '  if (it->fd < 0) return (uintptr_t)0;',
            '',
            '  // si el iterador ya terminó',
            '  if (it->finished) {',
            '    return (uintptr_t)0;',
            '  }',
            '',
            '  for (;;) {',
            '    if (it->bpos >= it->nread) {',
            '      it->nread = (int)syscall(SYS_getdents64, it->fd, it->buf, sizeof(it->buf));',
            '      it->bpos = 0;',
            '',
            '      // EOF real',
            '      if (it->nread <= 0) {',
            '        it->finished = 1;',
            '        return (uintptr_t)0;',
            '      }',
            '    }',
            '',
            '    struct linux_dirent64* d = (struct linux_dirent64*)(it->buf + it->bpos);',
            '    it->bpos += d->d_reclen;',
            '',
            '    // saltar "." y ".."',
            '    if (d->d_name[0] == \'.\' &&',
            '        (d->d_name[1] == 0 ||',
            '        (d->d_name[1] == \'.\' && d->d_name[2] == 0))) {',
            '      continue;',
            '    }',
            '',
            '    if (d->d_reclen == 0) {',
            '      it->finished = 1;',
            '      return (uintptr_t)0;',
            '    }',
            '',
            '    char* out = dir_name_bufs[dir_name_idx++ & 15];',
            '',
            '    int i = 0;',
            '    while (d->d_name[i] && i < MAX_DIRENT_NAME - 1) {',
            '      out[i] = d->d_name[i];',
            '      i++;',
            '    }',
            '    out[i] = 0;',
            '',
            '    return (uintptr_t)out;',
            '  }',
            '}',



            'static void soul_dir_close(uintptr_t h) {',
            '  if (h == 0) return;',
            '  int idx = (int)h - 1;',
            '  if (idx < 0 || idx >= 8) return;',
            '  dir_iter_t* it = &iters[idx];',
            '  if (it->fd >= 0) syscall(SYS_close, it->fd);',
            '  it->fd = -1;',
            '  it->nread = 0;',
            '  it->bpos  = 0;',
            '}',
            '',
            '// --------------------------------------------------',
            '// Network runtime (slots + syscalls)',
            '// --------------------------------------------------',
            '',
            '#define MAX_NET_SLOTS 8',
            '',
            'typedef struct {',
            '  int fd;',
            '  int status;', // 0 libre, 1 conectado
            '} soul_net_slot;',
            '',
            'static soul_net_slot net_slots[MAX_NET_SLOTS];',
            'static int net_inited = 0;',
            '',
            'static void soul_net_init_if_needed() {',
            '  if (net_inited) return;',
            '  for (int i = 0; i < MAX_NET_SLOTS; i++) {',
            '    net_slots[i].fd = -1;',
            '    net_slots[i].status = 0;',
            '  }',
            '  net_inited = 1;',
            '}',
            '',
            'static uint16_t soul_htons(uint16_t x) {',
            '  return (uint16_t)((x << 8) | (x >> 8));',
            '}',
            '',
            'static uint32_t soul_htonl(uint32_t x) {',
            '    return ((x & 0x000000FF) << 24) |',
            '           ((x & 0x0000FF00) << 8)  |',
            '           ((x & 0x00FF0000) >> 8)  |',
            '           ((x & 0xFF000000) >> 24);',
            '}',
            '',
            '// --------------------------------------------------',
            '// Parallel workers (for future parallel execution)',
            '// --------------------------------------------------',
            '#include <sys/types.h>',
            '#include <sys/wait.h>',
            '#include <stdlib.h>',
            '',
            'static int soul_worker_id = 0;',
            'static int soul_worker_count = 1;',
            'static int soul_worker_inited = 0;',
            'static int soul_job_id = 0;',
            '',
            'static void soul_worker_init(void) {',
            '    if (soul_worker_inited) return;',
            '    const char* sid = getenv("SOUL_WORKER_ID");',
            '    const char* sc  = getenv("SOUL_WORKER_COUNT");',
            '    const char* sj  = getenv("SOUL_JOB_ID");',
            '    if (sj) soul_job_id = atoi(sj);',
            '    if (sid) soul_worker_id = atoi(sid);',
            '    if (sc)  soul_worker_count = atoi(sc);',
            '    soul_worker_inited = 1;',
            '}',
            '',
            'static uintptr_t soul_worker_id_fn(void) {',
            '    soul_worker_init();',
            '    return (uintptr_t)soul_worker_id;',
            '}',
            '',
            'static uintptr_t soul_worker_count_fn(void) {',
            '    soul_worker_init();',
            '    return (uintptr_t)soul_worker_count;',
            '}',
            '',
            'static void soul_parallel_run(int workers) {',
            '    if (workers <= 1) return;',
            '',
            '    // Si ya vienes con env vars, no lances hijos otra vez',
            '    soul_worker_init();',
            ' if (getenv("SOUL_WORKER_ID") && getenv("SOUL_WORKER_COUNT")) return;',
            '',
            '    // Parent (worker 0) lanza N-1 hijos',
                'soul_job_id = getpid();',
                '{',
                '    char sj[16];',
                '    snprintf(sj, sizeof(sj), "%d", soul_job_id);',
                '    setenv("SOUL_JOB_ID", sj, 1);',
                '}',
                '',
            '    for (int i = 1; i < workers; i++) {',
            '        pid_t pid = fork();',
            '        if (pid == 0) {',
            '            // Child: set env and continue execution',
            '            char sid[16], sc[16];',
            '            snprintf(sid, sizeof(sid), "%d", i);',
            '            snprintf(sc,  sizeof(sc),  "%d", workers);',
            '            setenv("SOUL_WORKER_ID", sid, 1);',
            '            setenv("SOUL_WORKER_COUNT", sc, 1);',
            '            soul_worker_inited = 0;',
            '            soul_worker_init();',
            '            return; // el hijo vuelve y ejecuta el bloque',
            '        }',
            '    }',
            '',
            '    // Parent se queda con worker 0',
            '    {',
            '        char sid[16], sc[16];',
            '        snprintf(sid, sizeof(sid), "%d", 0);',
            '        snprintf(sc,  sizeof(sc),  "%d", workers);',
            '        setenv("SOUL_WORKER_ID", sid, 1);',
            '        setenv("SOUL_WORKER_COUNT", sc, 1);',
            '        soul_worker_inited = 0;',
            '        soul_worker_init();',
            '    }',
            '}',
            '',
            'static void soul_parallel_wait(int workers) {',
            '    soul_worker_init();',
            '    if (soul_worker_id != 0) return; // solo espera el padre',
            '    if (workers <= 1) return;',
            '',
            '    
                int hadError = 0;

                for (int i = 1; i < workers; i++) {
                    int status = 0;
                    wait(&status);

                    if (!WIFEXITED(status) || WEXITSTATUS(status) != 0) {
                        hadError = 1;
                    }
                }

                if (hadError) {
                    exit(1);
                }

            ',
            '',
            '    // limpieza de temporales del job (solo el padre, cuando todos han terminado)',
            '    {',
            '        char lstPath[256];',
            '        char metaPath[256];',
            '        ',
            '        char counterPath[256];',
            '        char startPath[256];',
            '',
            '        snprintf(lstPath, sizeof(lstPath), "/tmp/soul_copy_%d.lst", soul_job_id);',
            '        snprintf(metaPath, sizeof(metaPath), "/tmp/soul_copy_%d.meta", soul_job_id);',
            '        snprintf(counterPath, sizeof(counterPath), "/tmp/soul_counter_copy_idx_%d", soul_job_id);',
            '        snprintf(startPath, sizeof(startPath), "/tmp/soul_copy_%d.start", soul_job_id);',
            '',
            '        unlink(lstPath);',
            '        unlink(metaPath);',
            '        unlink(startPath);',
            '        if (soul_worker_id == 0) {',
            '            unlink(counterPath);',
            '        }',
            '    }',
            '}',
            '',
            'static char soul_copy_list_path[256];',  
            'static char soul_backup_log_path[256];',       
            'static int soul_backup_depth = 0;',
            'static char soul_backup_root_dst[4096];',            
            'static uintptr_t soul_copyFolder_parallel(const char* src, const char* dst);',
            '
            static uintptr_t soul_copyFolder(const char* src, const char* dst) {
                soul_parallel_run(4);          // o detectado por cores
                uintptr_t r = soul_copyFolder_parallel(src, dst);
                soul_parallel_wait(4);
                return r;
            }

            ',
            'static void soul_now_iso(char* buf, size_t sz) {',
            '    struct timespec ts;',
            '    clock_gettime(CLOCK_REALTIME, &ts);',
            '',
            '    struct tm tm;',
            '    localtime_r(&ts.tv_sec, &tm);',
            '',
            '    snprintf(',
            '        buf, sz,',
            '        "%04d-%02d-%02dT%02d:%02d:%02d",',
            '        tm.tm_year + 1900,',
            '        tm.tm_mon + 1,',
            '        tm.tm_mday,',
            '        tm.tm_hour,',
            '        tm.tm_min,',
            '        tm.tm_sec',
            '    );',
            '}',
            'static uintptr_t soul_atomic_append(const char* path, const char* text);',
            'static uintptr_t soul_backupFolder(const char* src, const char* dst) {',
            '
                soul_worker_init();

                // Parse flags only once per process
                if (soul_worker_id == 0) {
                    if (soul_arg("--continue-on-error", 0)) {
                        soul_copy_fail_fast = 0;
                    }
                    if (soul_arg("--no-verify", 0)) {
                        soul_copy_verify_enabled = 0;
                    }
                }                

                int is_root = (soul_backup_depth == 0);
                if (is_root) {
                    soul_progress_begin("backup");

                    snprintf(
                        soul_backup_root_dst,
                        sizeof(soul_backup_root_dst),
                        "%s",
                        dst
                    );

                    snprintf(
                        soul_backup_log_path,
                        sizeof(soul_backup_log_path),
                        "%s/.soul_backup.log",
                        soul_backup_root_dst
                    );

                    if (soul_worker_id == 0) {
                        char ts[64];
                        char hdr[512];
                        soul_now_iso(ts, sizeof(ts));

                        snprintf(
                            hdr, sizeof(hdr),
                            "\\n========================================\\n"
                            "BACKUP START %s\\n"
                            "SRC: %s\\n"
                            "DST: %s\\n"
                            "----------------------------------------\\n",
                            ts, src, dst
                        );

                        soul_atomic_append(soul_backup_log_path, hdr);
                    }
                }

                soul_backup_depth++;
            ',
            '    struct stat stSrc;',
            '',
            '    if (stat(src, &stSrc) != 0) {',
            '        soul_backup_depth--;',
            '        return 0;',
            '    }',
            '',
            '    // ================= DIR =================',
            '    if (S_ISDIR(stSrc.st_mode)) {',
            '        syscall(SYS_mkdirat, AT_FDCWD, dst, 0755);',
            '',
            '        int fd = (int)syscall(SYS_openat, AT_FDCWD, src, O_RDONLY | O_DIRECTORY, 0);',
            '        if (fd < 0) {',
            '            soul_backup_depth--;',
            '            return 0;',
            '        }',
            '',
            '        char buf[1 << 16];',
            '        for (;;) {',
            '            int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
            '            if (nread <= 0) break;',
            '',
            '            int bpos = 0;',
            '            while (bpos < nread) {',
            '                if (is_root) soul_progress_tick();',
            '',
            '                struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
            '                bpos += d->d_reclen;',
            '',
            '                if (d->d_name[0] == \'.\' &&',
            '                   (!d->d_name[1] || (d->d_name[1] == \'.\' && !d->d_name[2])))',
            '                    continue;',
            '',
            '                char srcPath[4096];',
            '                char dstPath[4096];',
            '',
            '                snprintf(srcPath, sizeof(srcPath), "%s/%s", src, d->d_name);',
            '                snprintf(dstPath, sizeof(dstPath), "%s/%s", dst, d->d_name);',
            '',
            '                soul_backupFolder(srcPath, dstPath);',
            '            }',
            '        }',
            '',
            '        syscall(SYS_close, fd);',
            '',
            '        soul_backup_depth--;',

            '        if (is_root && soul_worker_id == 0) {',
            '            char ts[64];',
            '            char ftr[256];',
            '            soul_now_iso(ts, sizeof(ts));',
            '',
            '            snprintf(',
            '                ftr, sizeof(ftr),',
            '                "----------------------------------------\\n"',
            '                "BACKUP END   %s\\n"',
            '                "========================================\\n",',
            '                ts',
            '            );',
            '',
            '            soul_atomic_append(soul_backup_log_path, ftr);',
            '',
            '            // ===== JSON resumen =====',
            '            char jsonPath[4096];',
            '            snprintf(jsonPath, sizeof(jsonPath), "%s/backup.json", soul_backup_root_dst);',
            '            soul_write_file(jsonPath, "{\\"status\\":\\"ok\\"}\\n");',
            '',
            '            soul_progress_end(1);',
            '        }',
            '',
            '        return 1;',
            '    }',
            '',
            '    // ================= FILE =================',
            '    if (S_ISREG(stSrc.st_mode)) {',
            '        soul_copy(src, dst);',
            '        soul_backup_depth--;',
            '        return 1;',
            '    }',
            '',
            '    soul_backup_depth--;',
            '    return 0;',
            '}',
            '',
            '// IPv4: "A.B.C.D" -> uint32 (network order)',
            'static uint32_t soul_inet_addr(const char* s) {',
            '  uint32_t parts[4];',
            '  int part = 0;',
            '  uint32_t val = 0;',
            '  int has = 0;',
            '',
            '  for (;;) {',
            '    char c = *s++;',
            '    if (c >= \'0\' && c <= \'9\') {',
            '      has = 1;',
            '      val = val * 10 + (uint32_t)(c - \'0\');',
            '      if (val > 255) return 0;',
            '      continue;',
            '    }',
            '    if (c == \'.\' || c == 0) {',
            '      if (!has || part >= 4) return 0;',
            '      parts[part++] = val;',
            '      val = 0;',
            '      has = 0;',
            '      if (c == 0) break;',
            '      continue;',
            '    }',
            '    return 0;',
            '  }',
            '',
            '  if (part != 4) return 0;',
            '',
            '  uint32_t ip =',
            '    (parts[0] << 24) |',
            '    (parts[1] << 16) |',
            '    (parts[2] << 8)  |',
            '    parts[3];',
            '',
            '  return ip;',
            '}',
            '',
            'static int soul_net_slot_alloc() {',
            '  for (int i = 0; i < MAX_NET_SLOTS; i++) {',
            '    if (net_slots[i].status == 0) return i;',
            '  }',
            '  return -1;',
            '}',
            '',
            'static int soul_net_slot_from_handle(uintptr_t h) {',
            '  if (h == 0) return -1;',
            '  int i = (int)h - 1;',
            '  if (i < 0 || i >= MAX_NET_SLOTS) return -1;',
            '  if (net_slots[i].status != 1) return -1;',
            '  return i;',
            '}',
            '',
            'static uintptr_t soul_http_get(const char* ip, int port, const char* path) {',
            '  soul_net_init_if_needed();',
            '',
            '  int slot = soul_net_slot_alloc();',
            '  if (slot < 0) return 0;',
            '',
            '  int fd = (int)syscall(SYS_socket, 2, 1, 0);',
            '  if (fd < 0) return 0;',

            '',
            '  struct {',
            '    uint16_t sin_family;',
            '    uint16_t sin_port;',
            '    uint32_t sin_addr;',
            '    char sin_zero[8];',
            '  } addr;',
            '',
            '  addr.sin_family = 2;',
            '  addr.sin_port = soul_htons((uint16_t)port);',
            '  addr.sin_addr = soul_inet_addr(ip);',
            '',
            '  for (int i = 0; i < 8; i++) addr.sin_zero[i] = 0;',
            '',
            '  if (addr.sin_addr == 0) {',
            '    syscall(SYS_close, fd);',
            '    return 0;',
            '  }',
            '',
            '  if (syscall(SYS_connect, fd, &addr, sizeof(addr)) < 0) {',
            '    syscall(SYS_close, fd);',
            '    return 0;',
            '  }',
            '',
            '  net_slots[slot].fd = fd;',
            '  net_slots[slot].status = 1;',
            '',
            '  char req[512];',
            '  int n = 0;',
            '',
            '  n += snprintf(req + n, sizeof(req) - n, "GET %s%s HTTP/1.0\\r\\n",',
            '                (path && path[0] == \'/\') ? "" : "/", path ? path : "/");',
            '  n += snprintf(req + n, sizeof(req) - n, "Host: %s\\r\\n", ip);',
            '  n += snprintf(req + n, sizeof(req) - n, "Connection: close\\r\\n\\r\\n");',
            '',
            '  syscall(SYS_write, fd, req, n);',
            '',
            '  return (uintptr_t)(slot + 1);',
            '}',
            '',
        '// --------------------------------------------------',
        '// Generic filtered walker (base para removeByExt / removeOlderThan / etc.)',
        '// --------------------------------------------------',
        '',
        'typedef int (*soul_predicate_fn)(const char* name, const char* fullPath, const struct stat* st, void* ctx);',
        '',
        'static uintptr_t soul_walk_filtered(const char* path, soul_predicate_fn pred, void* ctx) {',
        '    if (!path || !path[0]) return 0;',
        '',
        '    static int depth = 0;',
        '    depth++;',
        '',
        '    int is_root = (depth == 1);',
        '    if (is_root) soul_progress_begin("remove-filtered");',
        '',
        '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDONLY | O_DIRECTORY, 0);',
        '    if (fd < 0) { depth--; return 0; }',
        '',
        '    char buf[1 << 16];',
        '',
        '    for (;;) {',
        '        int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
        '        if (nread <= 0) break;',
        '',
        '        int bpos = 0;',
        '        while (bpos < nread) {',
        '            if (is_root) soul_progress_tick();',
        '',
        '            struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
        '            bpos += d->d_reclen;',
        '',
        '            if (d->d_name[0] == \'.\' && (!d->d_name[1] || (d->d_name[1]==\'.\' && !d->d_name[2]))) continue;',
        '',
        '            char child[4096];',
        '            snprintf(child, sizeof(child), "%s/%s", path, d->d_name);',
        '',
        '#ifndef DT_DIR',
        '#define DT_DIR 4',
        '#endif',
        '#ifndef DT_REG',
        '#define DT_REG 8',
        '#endif',
        '',
        '            if (d->d_type == DT_DIR) {',
        '                soul_walk_filtered(child, pred, ctx);',
        '                continue;',
        '            }',
        '',
        '            if (d->d_type == DT_REG) {',
        '                struct stat st;',
        '                if (lstat(child, &st) == 0) {',
        '                    if (pred(d->d_name, child, &st, ctx)) {',
        '                        syscall(SYS_unlinkat, AT_FDCWD, child, 0);',
        '                    }',
        '                }',
        '            }',
        '        }',
        '    }',
        '',
        '    syscall(SYS_close, fd);',
        '',
        '    depth--;',
        '    if (is_root) soul_progress_end(1);',
        '',
        '    return 1;',
        '}',
        '',
        '// --------------------------------------------------',
        '// removeByExt(path, ext)  (ultra fast filtered delete)',
        '// --------------------------------------------------',

        'static int soul_has_ext(const char* name, const char* ext) {',
        '    if (!name || !ext) return 0;',
        '',
        '    int ln = 0; while (name[ln]) ln++;',
        '    int le = 0; while (ext[le]) le++;',
        '',
        '    if (le > ln) return 0;',
        '',
        '    for (int i = 0; i < le; i++) {',
        '        if (name[ln - le + i] != ext[i]) return 0;',
        '    }',
        '',
        '    return 1;',
        '}',
        '
            static int pred_ext(const char* name, const char* fullPath, const struct stat* st, void* ctx) {
                (void)fullPath;
                (void)st;
                return soul_has_ext(name, (const char*)ctx);
            }

            static uintptr_t soul_removeByExt(const char* path, const char* ext) {
                if (!path || !ext) return 0;
                return soul_walk_filtered(path, pred_ext, (void*)ext);
            }
                    
        ',
        '
            // --------------------------------------------------
            // removeBiggerThan(path, bytes)
            // --------------------------------------------------

            typedef struct {
                int64_t minSize;
            } soul_pred_size_ctx;

            static int pred_bigger_than(
                const char* name,
                const char* fullPath,
                const struct stat* st,
                void* ctx
            ) {
                (void)name;
                (void)fullPath;

                soul_pred_size_ctx* c = (soul_pred_size_ctx*)ctx;
                return st->st_size > c->minSize;
            }

            static uintptr_t soul_removeBiggerThan(const char* path, int64_t bytes) {
                if (!path) return 0;

                soul_pred_size_ctx ctx;
                ctx.minSize = bytes;

                return soul_walk_filtered(path, pred_bigger_than, &ctx);
            }
                    
        ',

            '',
            'static uintptr_t soul_remove(const char* path) {',
            'if (!path) return 0;',
            'return syscall(SYS_unlinkat, AT_FDCWD, path, 0) == 0 ? 1 : 0;',
            '}',
            'static uintptr_t soul_net_read(uintptr_t h, int64_t max) {',
            '  int slot = soul_net_slot_from_handle(h);',
            '  if (slot < 0) return 0;',
            '',
            '  static char bufs[8][4096];',
            '  static int idx = 0;',
            '  char* buf = bufs[(idx++) & 7];',
            '',
            '  if (max <= 0 || max > 4095) max = 4095;',
            '',
            '  int n = (int)syscall(SYS_read, net_slots[slot].fd, buf, (size_t)max);',
            '  if (n < 0) {',
            '    if (errno == EAGAIN || errno == EWOULDBLOCK) {',
            '        return (uintptr_t)""; // no hay datos ahora',
            '    }',
            '    return 0; // error real',
            '}',
            '',
            'if (n == 0) return 0;',
            '',
            '  buf[n] = 0;',
            '  return (uintptr_t)buf;',
            '}',
            '',
            'static void soul_net_close(uintptr_t h) {',
            '  int slot = soul_net_slot_from_handle(h);',
            '  if (slot < 0) return;',
            '',
            '  syscall(SYS_close, net_slots[slot].fd);',
            '  net_slots[slot].fd = -1;',
            '  net_slots[slot].status = 0;',
            '}',
            '',
            '// --------------------------------------------------',
            '// Atomic counter and atomic append (multi-process safe)',
            '// --------------------------------------------------',
            '',
            'static uintptr_t soul_atomic_counter(const char* name) {',
            '    if (!name || !name[0]) return 0;',
            '',
            '    static char path[256];',
            '    int n = snprintf(path, sizeof(path), "/tmp/soul_counter_%s", name);',
            '    if (n <= 0 || n >= (int)sizeof(path)) return 0;',
            '',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDWR | O_CREAT, 0644);',
            '    if (fd < 0) return 0;',
            '',
            '    if (flock(fd, LOCK_EX) != 0) {',
            '        syscall(SYS_close, fd);',
            '        return 0;',
            '    }',
            '',
            '    char buf[64];',
            '    long nread = syscall(SYS_read, fd, buf, sizeof(buf) - 1);',
            '    int64_t val = 0;',
            '    if (nread > 0) {',
            '        buf[nread] = 0;',
            '        val = atoll(buf);',
            '    }',
            '',
            '    val += 1;',
            '',
            '    // rewind y truncar',
            '    lseek(fd, 0, SEEK_SET);',
            '    int len = snprintf(buf, sizeof(buf), "%lld", (long long)val);',
            '    if (len < 0) len = 0;',
            '    syscall(SYS_write, fd, buf, (size_t)len);',
            '    ftruncate(fd, (off_t)len);',
            '',
            '    flock(fd, LOCK_UN);',
            '    syscall(SYS_close, fd);',
            '',
            '    return (uintptr_t)val;',
            '}',
            '',
            'static uintptr_t soul_atomic_append(const char* path, const char* text) {',
            '    if (!path || !text) return 0;',
            '',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_WRONLY | O_CREAT | O_APPEND, 0644);',
            '    if (fd < 0) return 0;',
            '',
            '    if (flock(fd, LOCK_EX) != 0) {',
            '        syscall(SYS_close, fd);',
            '        return 0;',
            '    }',
            '',
            '    size_t len = 0;',
            '    while (text[len]) len++;',
            '',
            '    long w = syscall(SYS_write, fd, text, len);',
            '',
            '    flock(fd, LOCK_UN);',
            '    syscall(SYS_close, fd);',
            '',
            '    return (w == (long)len) ? 1 : 0;',
            '}',   
            '',
            'static void soul_ov_add_ext(soul_overview_t* ov, const char* name, int64_t size) {',
            '    if (!name || !name[0]) return;',
            '',
            '    // buscar si ya existe',
            '    for (int i = 0; i < ov->extTop; i++) {',
            '        if (strncmp(ov->extNames[i], name, SOUL_OV_EXT_LEN) == 0) {',
            '            ov->extFiles[i]++;',
            '            ov->extSize[i] += size;',
            '            return;',
            '        }',
            '    }',
            '',
            '    // nueva extensión',
            '    ov->extCount++;',
            '',
            '    if (ov->extTop < SOUL_OV_MAX_EXT) {',
            '        int i = ov->extTop++;',
            '        strncpy(ov->extNames[i], name, SOUL_OV_EXT_LEN - 1);',
            '        ov->extNames[i][SOUL_OV_EXT_LEN - 1] = 0;',
            '        ov->extFiles[i] = 1;',
            '        ov->extSize[i]  = size;',
            '    }',
            '}',
            '',
            'static const char* soul_ov_ext(const char* name) {',
            '    const char* dot = NULL;',
            '    const char* p = name;',
            '    while (*p) { if (*p == \'.\') dot = p; p++; }',
            '    if (!dot || dot == name) return NULL;',
            '    return dot + 1;',
            '}',
            '',
            'static void soul_ov_scan(const char* path, soul_overview_t* ov) {',
            '    struct stat st;',
            '    if (lstat(path, &st) != 0) return;',
            '',
            '    if (S_ISDIR(st.st_mode)) {',
            '        ov->folders++;',
            '',
            '        int fd = (int)syscall(SYS_openat, AT_FDCWD, path, O_RDONLY | O_DIRECTORY, 0);',
            '        if (fd < 0) return;',
            '',
            '        char buf[1 << 16];',
            '        for (;;) {',
            '            int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
            '            if (nread <= 0) break;',
            '',
            '            int bpos = 0;',
            '            while (bpos < nread) {',
            '                struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
            '                bpos += d->d_reclen;',
            '',
            '                if (d->d_name[0] == \'.\' &&',
            '                   (!d->d_name[1] || (d->d_name[1] == \'.\' && !d->d_name[2])))',
            '                    continue;',
            '',
            '                char child[4096];',
            '                snprintf(child, sizeof(child), "%s/%s", path, d->d_name);',
            '                soul_ov_scan(child, ov);',
            '            }',
            '        }',
            '',
            '        syscall(SYS_close, fd);',
            '        return;',
            '    }',
            '',
            '    if (S_ISREG(st.st_mode)) {',
            '        ov->files++;',
            '        ov->size += st.st_size;',
            '',
            '        const char* ext = soul_ov_ext(path);',
            '        if (ext) soul_ov_add_ext(ov, ext, st.st_size);',
            '        return;',
            '    }',
            '}',
            '',
            'static soul_overview_t soul_overview_pool[4];',
            'static int soul_overview_idx = 0;',
            '',
            'static uintptr_t soul_overview(const char* path) {',
            '    if (!path || !path[0]) return 0;',
            '',
            '    soul_overview_t* ov = &soul_overview_pool[soul_overview_idx++ & 3];',
            '    memset(ov, 0, sizeof(*ov));',
            '',
            '    soul_ov_scan(path, ov);',
            '',
            '    return (uintptr_t)ov;',
            '}',
            '',
            'static uintptr_t soul_overviewFiles(uintptr_t p) {',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    if (!ov) return 0;',
            '    return (uintptr_t)ov->files;',
            '}',
            '',
            'static uintptr_t soul_overviewDirs(uintptr_t p) {',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    if (!ov) return 0;',
            '    return (uintptr_t)ov->folders;',
            '}',
            'static uintptr_t soul_overview_files(uintptr_t p) {',
            '    if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    return (uintptr_t)ov->files;',
            '}',
            '',
            'static uintptr_t soul_overview_dirs(uintptr_t p) {',
            '    if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    return (uintptr_t)ov->folders;',
            '}',
            '',
            'static uintptr_t soul_overview_size(uintptr_t p) {',
            '    if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    return (uintptr_t)ov->size;',
            '}',
            '',
            'static uintptr_t soul_overview_ext_count(uintptr_t p) {',
            '    if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    return (uintptr_t)ov->extTop;',
            '}',
            '',
            'static uintptr_t soul_overview_ext_name(uintptr_t p, int64_t i) {',
            '    if (!p) return (uintptr_t)0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    if (i < 0 || i >= ov->extTop) return (uintptr_t)0;',
            '    return (uintptr_t)ov->extNames[i];',
            '}',
            '',
            'static uintptr_t soul_overview_ext_files(uintptr_t p, int64_t i) {',
            '   if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    if (i < 0 || i >= ov->extTop) return 0;',
            '    return (uintptr_t)ov->extFiles[i];',
            '}',
            '',
            'static uintptr_t soul_overview_ext_size(uintptr_t p, int64_t i) {',
            '    if (!p) return 0;',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '    if (i < 0 || i >= ov->extTop) return 0;',
            '    return (uintptr_t)ov->extSize[i];',
            '}',
            '// --------------------------------------------------',
            '// overviewSummary: human-readable summary',
            '// --------------------------------------------------',
            '',
            'static const char* soul_fmt_size(int64_t bytes) {',
            '    static char buf[64];',
            '    if (bytes < 1024) {',
            '        snprintf(buf, sizeof(buf), "%lld B", (long long)bytes);',
            '    } else if (bytes < 1024LL * 1024) {',
            '        snprintf(buf, sizeof(buf), "%.1f KB", bytes / 1024.0);',
            '    } else if (bytes < 1024LL * 1024 * 1024) {',
            '        snprintf(buf, sizeof(buf), "%.1f MB", bytes / (1024.0 * 1024));',
            '    } else {',
            '        snprintf(buf, sizeof(buf), "%.2f GB", bytes / (1024.0 * 1024 * 1024));',
            '    }',
            '    return buf;',
            '}',
            '',
            'static uintptr_t soul_overviewSummary(uintptr_t p) {',
            '    if (!p) return (uintptr_t)"";',
            '',
            '    soul_overview_t* ov = (soul_overview_t*)p;',
            '',
            '    char tmp[4096];',
            '    int pos = 0;',
            '',
            '    pos += snprintf(tmp + pos, sizeof(tmp) - pos,',
            '        "Files: %lld\\nDirs: %lld\\nSize: %s\\n",',
            '        (long long)ov->files,',
            '        (long long)ov->folders,',
            '        soul_fmt_size(ov->size)',
            '    );',
            '',
            '    // ordenar extensiones por tamaño (burbuja simple, max 8)',
            '    for (int i = 0; i < ov->extTop; i++) {',
            '        for (int j = i + 1; j < ov->extTop; j++) {',
            '            if (ov->extSize[j] > ov->extSize[i]) {',
            '                char tmpName[SOUL_OV_EXT_LEN];',
            '                int64_t tmpFiles, tmpSize;',
            '',
            '                strncpy(tmpName, ov->extNames[i], SOUL_OV_EXT_LEN);',
            '                strncpy(ov->extNames[i], ov->extNames[j], SOUL_OV_EXT_LEN);',
            '                strncpy(ov->extNames[j], tmpName, SOUL_OV_EXT_LEN);',
            '',
            '                tmpFiles = ov->extFiles[i];',
            '                ov->extFiles[i] = ov->extFiles[j];',
            '                ov->extFiles[j] = tmpFiles;',
            '',
            '                tmpSize = ov->extSize[i];',
            '                ov->extSize[i] = ov->extSize[j];',
            '                ov->extSize[j] = tmpSize;',
            '            }',
            '        }',
            '    }',
            '',
            '    // top extensiones',
            '    for (int i = 0; i < ov->extTop && i < 5; i++) {',
            '        pos += snprintf(tmp + pos, sizeof(tmp) - pos,',
            '            "%s: %lld files, %s\\n",',
            '            ov->extNames[i],',
            '            (long long)ov->extFiles[i],',
            '            soul_fmt_size(ov->extSize[i])',
            '        );',
            '    }',
            '',
            '    tmp[pos] = 0;',
            '    return (uintptr_t)soul_persist_str(tmp);',
            '}',            
            '',
            // --------------------------------------------------',
            '// copyFolder debug + collector + parallel runner',
            '// DEBUG CENTRALIZADO (NO dprintf SUELTOS)',
            '// --------------------------------------------------',
            'static int soul_copy_debug = 0;', // <-- 0 = OFF | 1 = ON
            '',
            'static void dbg_printf(const char* tag, const char* fmt, ...) {',
            '  if (!soul_copy_debug) return;',
            '  va_list ap;',
            '  va_start(ap, fmt);',
            '  dprintf(2, "[DBG][%s][pid=%d wid=%d] ", tag, (int)getpid(), soul_worker_id);',
            '  vdprintf(2, fmt, ap);',
            '  dprintf(2, "\\n");',
            '  va_end(ap);',
            '}',
            '#define DBG(tag, ...) dbg_printf(tag, __VA_ARGS__)',
            '#define DBG_ERR(tag, msg) dbg_printf(tag, "%s (errno=%d: %s)", msg, errno, strerror(errno))',
            '',
            'static int64_t soul_copy_total_files = 0;',
            '',
            '// list path is filled per-job',
            'static char soul_copy_list_path[256];',
            '',
            'static void soul_copy_collect(const char* src, const char* dst) {',
            '  if (!src || !dst) return;',
            '',
            '  struct stat st;',
            '  if (lstat(src, &st) != 0) {',
            '    DBG_ERR("collect", "lstat");',
            '    return;',
            '  }',
            '',
            '  if (S_ISLNK(st.st_mode)) {',
            '    if (stat(src, &st) != 0) {',
            '      DBG_ERR("collect", "stat");',
            '      return;',
            '    }',
            '  }',
            '',
            '  if (S_ISDIR(st.st_mode)) {',
            '    {',
            '      char line[8192];',
            '      int n = snprintf(line, sizeof(line), "D|%s\\n", dst);',
            '      if (n > 0) soul_atomic_append(soul_copy_list_path, line);',
            '    }',
            '',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, src, O_RDONLY | O_DIRECTORY, 0);',
            '    if (fd < 0) {',
            '      DBG_ERR("collect", "openat");',
            '      return;',
            '    }',
            '',
            '    char buf[1 << 16];',
            '    for (;;) {',
            '      int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
            '      if (nread <= 0) break;',
            '',
            '      int bpos = 0;',
            '      while (bpos < nread) {',
            '        struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
            '        bpos += d->d_reclen;',
            '',
            '        if (d->d_name[0] == \'.\' && (!d->d_name[1] || (d->d_name[1] == \'.\' && !d->d_name[2])))',
            '          continue;',
            '',
            '        char srcChild[4096];',
            '        char dstChild[4096];',
            '        snprintf(srcChild, sizeof(srcChild), "%s/%s", src, d->d_name);',
            '        snprintf(dstChild, sizeof(dstChild), "%s/%s", dst, d->d_name);',
            '',
            '        soul_copy_collect(srcChild, dstChild);',
            '      }',
            '    }',
            '',
            '    syscall(SYS_close, fd);',
            '    return;',
            '  }',
            '',
            '  
                if (S_ISREG(st.st_mode)) {

                    struct stat stDst;

                    int needCopy = 0;

                    // destino no existe
                    if (stat(dst, &stDst) != 0) {
                        needCopy = 1;
                    }
                    // tamaño distinto
                    else if (st.st_size != stDst.st_size) {
                        needCopy = 1;
                    }
                    // mtime distinto (src más nuevo)
                    else if (st.st_mtime > stDst.st_mtime) {
                        needCopy = 1;
                    }

                    if (needCopy) {
                        char line[8192];
                        int n = snprintf(line, sizeof(line), "F|%s|%s\n", src, dst);
                        if (n > 0) soul_atomic_append(soul_copy_list_path, line);
                    }

                    return;
                }
                            
            ',
            '}',
            '',
            'static uintptr_t soul_copyFolder_parallel(const char* src, const char* dst) {',
            '  soul_worker_init();',
            '',
            '  char metaPath[256];',
            '  char readyPath[256];',
            '  char dirsReadyPath[256];',
            '',
            '  snprintf(readyPath, sizeof(readyPath), "/tmp/soul_copy_%d.ready", soul_job_id);',
            '  snprintf(dirsReadyPath, sizeof(dirsReadyPath), "/tmp/soul_copy_%d.dirsready", soul_job_id);',
            '  snprintf(soul_copy_list_path, sizeof(soul_copy_list_path), "/tmp/soul_copy_%d.lst", soul_job_id);',
            '  snprintf(metaPath, sizeof(metaPath), "/tmp/soul_copy_%d.meta", soul_job_id);',
            '',
            '  if (soul_worker_id == 0) {',
            '    unlink(readyPath);',
            '    unlink(dirsReadyPath);',
            '',
            '    int fd = (int)syscall(SYS_openat, AT_FDCWD, soul_copy_list_path, O_WRONLY | O_CREAT | O_TRUNC, 0644);',
            '    if (fd >= 0) syscall(SYS_close, fd);',
            '',
            '    soul_progress_begin("scan");',
            '    soul_copy_collect(src, dst);',
            '    soul_progress_end(1);',
            '',
            '    soul_copy_total_files = 0;',
            '    FILE* cf = fopen(soul_copy_list_path, "r");',
            '    if (cf) {',
            '      char line[8192];',
            '      while (fgets(line, sizeof(line), cf)) {',
            '        if (line[0] == \'F\' && line[1] == \'|\') soul_copy_total_files++;',
            '      }',
            '      fclose(cf);',
            '    }',
            '',
            '    FILE* mf = fopen(metaPath, "w");',
            '    if (mf) {',
            '      fprintf(mf, "%lld\\n", (long long)soul_copy_total_files);',
            '      fclose(mf);',
            '    }',
            '',
            '    fd = (int)syscall(SYS_openat, AT_FDCWD, readyPath, O_WRONLY | O_CREAT | O_TRUNC, 0644);',
            '    if (fd >= 0) syscall(SYS_close, fd);',
            '  }',
            '',
            '  while (access(readyPath, F_OK) != 0) usleep(1000);',
            '',
            '  FILE* mf = fopen(metaPath, "r");',
            '  if (!mf) return 0;',
            '  fscanf(mf, "%" SCNd64, &soul_copy_total_files);',
            '  fclose(mf);',
            '',
            '  int workers = soul_worker_count;',
            '  int wid = soul_worker_id;',
            '',
            '  int files_per_worker = (soul_copy_total_files + workers - 1) / workers;',
            '  int start = wid * files_per_worker;',
            '  int end   = start + files_per_worker;',
            '  if (end > soul_copy_total_files) end = soul_copy_total_files;',
            '',
            '  if (wid == 0) soul_progress_begin("copy");',
            '',
            '  FILE* f = fopen(soul_copy_list_path, "r");',
            '  if (!f) {',
            '    if (wid == 0) soul_progress_end(0);',
            '    return 0;',
            '  }',
            '',
            '  char line[8192];',
            '',
            '  if (wid == 0) {',
            '    while (fgets(line, sizeof(line), f)) {',
            '      if (line[0] == \'D\' && line[1] == \'|\') {',
            '        char* path = line + 2;',
            '        char* nl = strchr(path, \'\\n\');',
            '        if (nl) *nl = 0;',
            '        syscall(SYS_mkdirat, AT_FDCWD, path, 0755);',
            '      }',
            '    }',
            '',
            '    int fd2 = (int)syscall(SYS_openat, AT_FDCWD, dirsReadyPath, O_WRONLY | O_CREAT | O_TRUNC, 0644);',
            '    if (fd2 >= 0) syscall(SYS_close, fd2);',
            '  }',
            '',
            '  while (access(dirsReadyPath, F_OK) != 0) usleep(1000);',
            '',
            '  rewind(f);',
            '  int64_t fileIdx = 0;',
            '',
            '  while (fgets(line, sizeof(line), f)) {',
            '    if (line[0] == \'F\' && line[1] == \'|\') {',
            '      if (fileIdx >= start && fileIdx < end) {',
            '        char* p = line + 2;',
            '        char* sep = strchr(p, \'|\');',
            '        if (sep) {',
            '          *sep = 0;',
            '          char* srcP = p;',
            '          char* dstP = sep + 1;',
            '          char* nl = strchr(dstP, \'\\n\');',
            '          if (nl) *nl = 0;',
            '          
                        if (!soul_copy_verify(srcP, dstP, soul_copy_verify_enabled)) {

                            soul_copy_errors++;

                            dprintf(2, "[copy] integrity mismatch: %s\n", srcP);

                            if (soul_copy_fail_fast) {
                                fclose(f);
                                if (wid == 0) soul_progress_end(0);
                                exit(1);  // matar worker inmediatamente
                            }

                            // modo tolerante → continuar
                        }
       
            ',
            '        }',
            '      }',
            '      fileIdx++;',
            '      if (fileIdx >= end) break;',
            '    }',
            '  }',
            '',
            '  fclose(f);',
            '',
            '  
                if (wid == 0) {
                    if (soul_copy_errors > 0)
                        soul_progress_end(0);
                    else
                        soul_progress_end(1);
                }

                return soul_copy_errors == 0 ? 1 : 0;
            ',
            '}',




        ];
    }



    // --------------------------------------------------
    // Instruction emission
    // --------------------------------------------------

    private function emitInstruction(IrProgram $program, IrInstruction $inst, int $ip, array $openHandleVars): string
    {
        $op = $inst->opCode;
        $ops = $inst->operands;

        return match ($op) {

            OpCode::LOAD_INDEX => $this->emitLoadIndex($ops),

            // LOAD_CONST <lit> <dstReg>
            OpCode::LOAD_CONST => $this->emitLoadConst($ops),

            // LOAD_VAR <$var> <dstReg>
            OpCode::LOAD_VAR   => $this->emitLoadVar($ops),

            // STORE_VAR <srcReg> <$var>
            OpCode::STORE_VAR  => $this->emitStoreVar($ops),

            // Arithmetic: OP <aReg> <bReg> <dstReg>
            OpCode::ADD, OpCode::SUB, OpCode::MUL, OpCode::DIV, OpCode::MOD => $this->emitArith($op, $ops),

            // Comparisons: CMP_* <aReg> <bReg> <dstReg>
            OpCode::CMP_LT, OpCode::CMP_LE, OpCode::CMP_GT, OpCode::CMP_GE, OpCode::CMP_EQ, OpCode::CMP_NE
                => $this->emitCmp($op, $ops),

            // Unary: NEG/NOT <srcReg> <dstReg>
            OpCode::NEG, OpCode::NOT => $this->emitUnary($op, $ops),

            // LABEL <labelRef>
            OpCode::LABEL => $this->emitLabel($ops),

            // JMP <labelRef>
            OpCode::JMP => $this->emitJmp($ops),

            // JMP_IF_FALSE <condReg> <labelRef>
            OpCode::JMP_IF_FALSE => $this->emitJmpIfFalse($ops),

            // CALL_BUILTIN <lit(name)> <argReg>* [<dstReg>]
            //OpCode::CALL_BUILTIN => $this->emitCallBuiltin($ops),
            OpCode::CALL_BUILTIN => $this->emitCallBuiltin($program, $ops, $ip, $openHandleVars),

            // EXIT [<exitCodeReg>]
            OpCode::EXIT => $this->emitExit($ops),

            default => '  /* TODO unsupported opcode: ' . $op . ' */',
        };
    }

    private function emitLoadIndex(array $ops): string
    {
        [$arr, $idx, $dst] = $ops;

        $this->assertReg($arr, 'LOAD_INDEX');
        $this->assertReg($idx, 'LOAD_INDEX');
        $this->assertReg($dst, 'LOAD_INDEX');

        $ai = $this->regIndex($arr);
        $ii = $this->regIndex($idx);
        $di = $this->regIndex($dst);

        // Asumimos array de char* (strings)
        //return "  r[$di] = (int64_t)((char**)r[$ai])[r[$ii]];";
        return "  r[$di] = (uintptr_t)((char**)r[$ai])[(int64_t)r[$ii]];";

    }


    private function emitLoadConst(array $ops): string
    {
        [$lit, $dst] = $ops;
        $this->assertLit($lit, 'LOAD_CONST');
        $this->assertReg($dst, 'LOAD_CONST');

        $dstIdx = $this->regIndex($dst);

        $v = $lit->value;

        if (is_string($v)) {
            // puntero a literal string
            $escaped = addcslashes($v, "\\\"\n\r\t");
            $escaped = str_replace(["\n", "\r", "\t"], ["\\n", "\\r", "\\t"], $escaped);
            return "  r[$dstIdx] = (uintptr_t)\"$escaped\";";
        }

        if (is_bool($v)) {
            return "  r[$dstIdx] = " . ($v ? '1' : '0') . ";";
        }

        if (is_int($v)) {
            return "  r[$dstIdx] = (uintptr_t)$v;";
        }

        if (is_float($v)) {
            return "  r[$dstIdx] = (uintptr_t)(int64_t)$v;";
        }

        return "  r[$dstIdx] = 0;";
    }


    private function emitLoadVar(array $ops): string
    {
        [$var, $dst] = $ops;
        $this->assertVar($var, 'LOAD_VAR');
        $this->assertReg($dst, 'LOAD_VAR');

        $dstIdx = $this->regIndex($dst);
        $cVar = $this->cVar($var->name);

        return "  r[$dstIdx] = $cVar;";
    }

    private function emitStoreVar(array $ops): string
    {
        [$src, $var] = $ops;
        $this->assertReg($src, 'STORE_VAR');
        $this->assertVar($var, 'STORE_VAR');

        $srcIdx = $this->regIndex($src);
        $cVar = $this->cVar($var->name);

        return "  $cVar = r[$srcIdx];";
    }


    private function emitArith(string $op, array $ops): string
    {
        [$a, $b, $dst] = $ops;
        $this->assertReg($a, $op);
        $this->assertReg($b, $op);
        $this->assertReg($dst, $op);

        $ai = $this->regIndex($a);
        $bi = $this->regIndex($b);
        $di = $this->regIndex($dst);

        $cOp = match ($op) {
            OpCode::ADD => '+',
            OpCode::SUB => '-',
            OpCode::MUL => '*',
            OpCode::DIV => '/',
            OpCode::MOD => '%',
            default => '+',
        };

        if ($op === OpCode::MOD) {
            return "  r[$di] = ((int64_t)r[$bi] == 0) ? 0 : (uintptr_t)(((int64_t)r[$ai]) % ((int64_t)r[$bi]));";
        }

        return "  r[$di] = (uintptr_t)(((int64_t)r[$ai]) $cOp ((int64_t)r[$bi]));";

    }


    private function emitCmp(string $op, array $ops): string
    {
        [$a, $b, $dst] = $ops;
        $this->assertReg($a, $op);
        $this->assertReg($b, $op);
        $this->assertReg($dst, $op);

        $ai = $this->regIndex($a);
        $bi = $this->regIndex($b);
        $di = $this->regIndex($dst);

        $cOp = match ($op) {
            OpCode::CMP_LT => '<',
            OpCode::CMP_LE => '<=',
            OpCode::CMP_GT => '>',
            OpCode::CMP_GE => '>=',
            OpCode::CMP_EQ => '==',
            OpCode::CMP_NE => '!=',
            default => '==',
        };

        return "  r[$di] = (((int64_t)r[$ai]) $cOp ((int64_t)r[$bi])) ? 1 : 0;";
    }


    private function emitUnary(string $op, array $ops): string
    {
        [$src, $dst] = $ops;
        $this->assertReg($src, $op);
        $this->assertReg($dst, $op);

        $si = $this->regIndex($src);
        $di = $this->regIndex($dst);

        return match ($op) {
            OpCode::NEG => "  r[$di] = (uintptr_t)(-((int64_t)r[$si]));",
            OpCode::NOT => "  r[$di] = (!r[$si]) ? 1 : 0;",
            default => "  /* TODO unary $op */",
        };
    }

    private function emitLabel(array $ops): string
    {
        [$label] = $ops;
        $this->assertLabel($label, 'LABEL');
        return $label->name . ':';
    }

    private function emitJmp(array $ops): string
    {
        [$label] = $ops;
        $this->assertLabel($label, 'JMP');
        return '  goto ' . $label->name . ';';
    }

    private function emitJmpIfFalse(array $ops): string
    {
        [$cond, $label] = $ops;
        $this->assertReg($cond, 'JMP_IF_FALSE');
        $this->assertLabel($label, 'JMP_IF_FALSE');

        $ci = $this->regIndex($cond);
        return '  if (!r[' . $ci . ']) goto ' . $label->name . ';';
    }

    private function emitCallBuiltin(IrProgram $program, array $ops, int $ip, array $openHandleVars): string
    {
        // CALL_BUILTIN <lit(name)> <argReg>* [<dstReg>]
        $nameOp = $ops[0] ?? null;
        $this->assertLit($nameOp, 'CALL_BUILTIN');
        $name = $nameOp->value;

        if (!is_string($name)) {
            return '  /* ERROR: builtin name not string */';
        }
        


        $voidBuiltins = [
            'print', 'printStr',
            'dir_close', 'net_close',
            'move', 'append', 'copy',
            'write', 'close', 'remove', 'parallelRun', 'parallelWait',
            'copyFolder', 'backup', 'removeByExt', 'removeOlderThan', 'removeBiggerThan'
        ];


        $argsOperands = array_slice($ops, 1);

        $dstReg = null;

        // dst SOLO existe si el IR tiene más de:
        // 1 (nombre) + N argumentos
        // Es decir: si hay AL MENOS 3 operandos
        if (!in_array($name, $voidBuiltins, true) && count($ops) >= 3) {
            $last = end($argsOperands);
            if ($last instanceof IrReg) {
                $dstReg = array_pop($argsOperands);
            }
        }

        if ($name === 'mkdir') {
            if (count($argsOperands) !== 1) {
                throw new RuntimeException(
                    "CEmitter: builtin mkdir expects 1 arg, got " . count($argsOperands)
                );
            }

            $path = $argsOperands[0];
            $this->assertReg($path, 'CALL_BUILTIN mkdir');
            $pi = $this->regIndex($path);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_mkdir((const char*)r[$pi]);";
            }

            return "  soul_mkdir((const char*)r[$pi]);";
        }

        if ($name === 'analyze') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: analyze(path, topN) */';
            }

            [$path, $n] = $argsOperands;

            $pi = $this->regIndex($path);
            $ni = $this->regIndex($n);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_analyze((const char*)r[$pi], (int64_t)r[$ni]);";
        }        

        if ($name === 'analyzeFiles') {
            return $this->emitUnaryCall('soul_an_files', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeDirs') {
            return $this->emitUnaryCall('soul_an_dirs', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeSize') {
            return $this->emitUnaryCall('soul_an_size', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeExtCount') {
            return $this->emitUnaryCall('soul_an_ext_count', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeExtName') {
            return $this->emitBinaryCall('soul_an_ext_name', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeExtFiles') {
            return $this->emitBinaryCall('soul_an_ext_files', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeExtSize') {
            return $this->emitBinaryCall('soul_an_ext_size', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeTopCount') {
            return $this->emitUnaryCall('soul_an_top_count', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeTopPath') {
            return $this->emitBinaryCall('soul_an_top_path', $argsOperands, $dstReg);
        }

        if ($name === 'analyzeTopSize') {
            return $this->emitBinaryCall('soul_an_top_size', $argsOperands, $dstReg);
        }



        if ($name === 'now') {
            if ($dstReg === null) {
                return '  /* ERROR: now must return int */';
            }

            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_now();";
        }


        if ($name === 'overview') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: overview expects 1 arg and returns struct */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN overview');

            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_overview((const char*)r[$pi]);";
        }

        if ($name === 'overviewFiles') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: overviewFiles expects 1 arg */';
            }
            $ov = $argsOperands[0];
            $this->assertReg($ov, 'CALL_BUILTIN overviewFiles');
            $oi = $this->regIndex($ov);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_files(r[$oi]);";
        }

        if ($name === 'overviewDirs') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: overviewDirs expects 1 arg */';
            }
            $ov = $argsOperands[0];
            $this->assertReg($ov, 'CALL_BUILTIN overviewDirs');
            $oi = $this->regIndex($ov);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_dirs(r[$oi]);";
        }

        if ($name === 'overviewSize') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: overviewSize expects 1 arg */';
            }
            $ov = $argsOperands[0];
            $this->assertReg($ov, 'CALL_BUILTIN overviewSize');
            $oi = $this->regIndex($ov);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_size(r[$oi]);";
        }

        if ($name === 'overviewExtCount') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR */';
            }
            $ov = $argsOperands[0];
            $this->assertReg($ov, 'CALL_BUILTIN overviewExtCount');
            $oi = $this->regIndex($ov);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_ext_count(r[$oi]);";
        }

        if ($name === 'overviewExtName') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR */';
            }
            [$ov, $idx] = $argsOperands;
            $this->assertReg($ov, 'CALL_BUILTIN overviewExtName');
            $this->assertReg($idx, 'CALL_BUILTIN overviewExtName');
            $oi = $this->regIndex($ov);
            $ii = $this->regIndex($idx);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = (uintptr_t)soul_overview_ext_name(r[$oi], (int64_t)r[$ii]);";
        }

        if ($name === 'overviewExtFiles') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR */';
            }
            [$ov, $idx] = $argsOperands;
            $this->assertReg($ov, 'CALL_BUILTIN overviewExtFiles');
            $this->assertReg($idx, 'CALL_BUILTIN overviewExtFiles');
            $oi = $this->regIndex($ov);
            $ii = $this->regIndex($idx);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_ext_files(r[$oi], (int64_t)r[$ii]);";
        }

        if ($name === 'overviewExtSize') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR */';
            }
            [$ov, $idx] = $argsOperands;
            $this->assertReg($ov, 'CALL_BUILTIN overviewExtSize');
            $this->assertReg($idx, 'CALL_BUILTIN overviewExtSize');
            $oi = $this->regIndex($ov);
            $ii = $this->regIndex($idx);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_overview_ext_size(r[$oi], (int64_t)r[$ii]);";
        }
        
        if ($name === 'overviewSummary') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: overviewSummary expects 1 arg */';
            }

            [$ov] = $argsOperands;
            $this->assertReg($ov, 'CALL_BUILTIN overviewSummary');

            $oi = $this->regIndex($ov);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_overviewSummary(r[$oi]);";
        }

        if ($name === 'walk') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: walk expects 1 arg and returns handle */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN walk');

            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_walk((const char*)r[$pi]);";
        }

        if ($name === 'walk_next') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: walk_next expects 1 arg and returns path */';
            }

            [$h] = $argsOperands;
            $this->assertReg($h, 'CALL_BUILTIN walk_next');

            $hi = $this->regIndex($h);
            $di = $this->regIndex($dstReg);

            return
                "  { const char* __p = (const char*)soul_walk_next(r[$hi]);" .
                "    if (!__p || __p[0] == 0) r[$di] = (uintptr_t)0;" .
                "    else r[$di] = (uintptr_t)soul_persist_str(__p);" .
                "  }";
        }

        if ($name === 'walk_close') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: walk_close expects 1 arg */';
            }

            [$h] = $argsOperands;
            $this->assertReg($h, 'CALL_BUILTIN walk_close');

            $hi = $this->regIndex($h);

            return "  soul_walk_close(r[$hi]);";
        }


        if ($name === 'parallelRun') {
            if (count($argsOperands) !== 1) return '  /* ERROR: parallelRun expects 1 arg */';
            $this->assertReg($argsOperands[0], 'CALL_BUILTIN parallelRun');
            $wi = $this->regIndex($argsOperands[0]);
            return '  soul_parallel_run((int)r[' . $wi . ']);';
        }

        if ($name === 'parallelWait') {
            if (count($argsOperands) !== 1) return '  /* ERROR */';
            $this->assertReg($argsOperands[0], 'CALL_BUILTIN parallelWait');
            $wi = $this->regIndex($argsOperands[0]);

            return implode("\n", [
                '  soul_parallel_wait((int)r[' . $wi . ']);',
                '  if (soul_worker_id_fn() != 0) _exit(0);'
            ]);
        }  
        
        if ($name === 'print') {
            if (count($argsOperands) !== 1) return '  /* ERROR: print expects 1 arg */';
            $arg = $argsOperands[0];
            $this->assertReg($arg, 'CALL_BUILTIN print');
            $ai = $this->regIndex($arg);
            return '  printf("%lld\\n", (long long)(int64_t)r['.$ai.']);';
        }

        if ($name === 'printStr') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: printStr expects 1 arg */';
            }
            $arg = $argsOperands[0];
            $this->assertReg($arg, 'CALL_BUILTIN printStr');
            $ai = $this->regIndex($arg);

            return '  if (r['.$ai.']) printf("%s\\n", (const char*)r['.$ai.']);';
        }

        if ($name === 'move') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: move expects 2 args */';
            }

            [$src, $dst] = $argsOperands;

            $this->assertReg($src, 'CALL_BUILTIN move');
            $this->assertReg($dst, 'CALL_BUILTIN move');

            $si = $this->regIndex($src);
            $di = $this->regIndex($dst);

            return '  if (rename((char*)r['.$si.'], (char*)r['.$di.']) != 0) perror("move");';
        }

        if ($name === 'toStr') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: toStr expects 1 arg and returns str */';
            }

            [$v] = $argsOperands;
            $this->assertReg($v, 'CALL_BUILTIN toStr');

            $vi = $this->regIndex($v);
            $di = $this->regIndex($dstReg);

            //return "  r[$di] = soul_int_to_str((int64_t)r[$vi]);";
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_int_to_str((int64_t)r[$vi]));";

        }

        if ($name === 'join') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: join expects 2 args and returns value */';
            }

            [$a, $b] = $argsOperands;
            $this->assertReg($a, 'CALL_BUILTIN join');
            $this->assertReg($b, 'CALL_BUILTIN join');

            $ai = $this->regIndex($a);
            $bi = $this->regIndex($b);
            $di = $this->regIndex($dstReg);

            // join() DEBE ser path join con "/"
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_path_join((const char*)r[$ai], (const char*)r[$bi]));";
        }

        if ($name === 'rmdir') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: rmdir expects 1 arg */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN rmdir');
            $pi = $this->regIndex($path);

            if ($dstReg !== null) {
                $di = $this->regIndex($dstReg);
                return "  r[$di] = soul_rmdir((const char*)r[$pi]);";
            }

            return "  soul_rmdir((const char*)r[$pi]);";
        }

        if ($name === 'listdir') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: listdir expects 1 arg and returns handle */';
            }
            $arg = $argsOperands[0]; $this->assertReg($arg, 'CALL_BUILTIN listdir');
            $ai = $this->regIndex($arg);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_listdir((const char*)r[$ai]);";
        }

        if ($name === 'dir_next') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: dir_next expects 1 arg and returns path */';
            }
            $arg = $argsOperands[0]; $this->assertReg($arg, 'CALL_BUILTIN dir_next');
            $ai = $this->regIndex($arg);
            $di = $this->regIndex($dstReg);
            //return "  r[$di] = soul_dir_next(r[$ai]);";            
            //return "  { const char* __p = (const char*)soul_dir_next(r[$ai]); if (!__p || __p[0] == 0) { r[$di] = (uintptr_t)\"\"; } else { r[$di] = (uintptr_t)soul_persist_str(__p); } }";
            return "  { const char* __p = (const char*)soul_dir_next(r[$ai]); if (!__p || __p[0] == 0) { r[$di] = (uintptr_t)0; } else { r[$di] = (uintptr_t)soul_persist_str(__p); } }";



        }

        if ($name === 'endswith') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: endswith expects 2 args and returns bool */';
            }

            [$text, $suffix] = $argsOperands;
            $this->assertReg($text, 'CALL_BUILTIN endswith');
            $this->assertReg($suffix, 'CALL_BUILTIN endswith');

            $ti = $this->regIndex($text);
            $si = $this->regIndex($suffix);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_endswith((const char*)r[$ti], (const char*)r[$si]);";
        }

        if ($name === 'mtime') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: mtime expects 1 arg and returns int */';
            }

            [$path] = $argsOperands;

            $this->assertReg($path, 'CALL_BUILTIN mtime');
            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_mtime((const char*)r[$pi]);";
        }

        if ($name === 'remove') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: remove expects 1 arg */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN remove');

            $pi = $this->regIndex($path);

            if ($dstReg !== null) {
                $di = $this->regIndex($dstReg);
                return "  r[$di] = soul_remove((const char*)r[$pi]);";
            }

            return "  soul_remove((const char*)r[$pi]);";
        }

        if ($name === 'toInt') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: toInt expects 1 arg and returns int */';
            }

            [$v] = $argsOperands;
            $this->assertReg($v, 'CALL_BUILTIN toInt');

            $vi = $this->regIndex($v);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_to_int((const char*)r[$vi]);";
        }        

        if ($name === 'startswith') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: startswith expects 2 args and returns bool */';
            }

            [$text, $prefix] = $argsOperands;
            $this->assertReg($text, 'CALL_BUILTIN startswith');
            $this->assertReg($prefix, 'CALL_BUILTIN startswith');

            $ti = $this->regIndex($text);
            $pi = $this->regIndex($prefix);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_startswith((const char*)r[$ti], (const char*)r[$pi]);";
        }

        if ($name === 'length') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: length expects 1 arg and returns int */';
            }

            [$text] = $argsOperands;
            $this->assertReg($text, 'CALL_BUILTIN length');

            $ti = $this->regIndex($text);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_strlen((const char*)r[$ti]);";
        }

        if ($name === 'slice') {
            if (count($argsOperands) !== 3 || $dstReg === null) {
                return '  /* ERROR: slice expects 3 args and returns str */';
            }

            [$text, $start, $len] = $argsOperands;

            $this->assertReg($text,  'CALL_BUILTIN slice');
            $this->assertReg($start, 'CALL_BUILTIN slice');
            $this->assertReg($len,   'CALL_BUILTIN slice');

            $ti = $this->regIndex($text);
            $si = $this->regIndex($start);
            $li = $this->regIndex($len);
            $di = $this->regIndex($dstReg);

            //return "  r[$di] = soul_slice((const char*)r[$ti], (int64_t)r[$si], (int64_t)r[$li]);";
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_slice((const char*)r[$ti], (int64_t)r[$si], (int64_t)r[$li]));";

        }

        if ($name === 'concat') {
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: concat expects 2 args and returns str */';
            }

            [$a, $b] = $argsOperands;
            $this->assertReg($a, 'CALL_BUILTIN concat');
            $this->assertReg($b, 'CALL_BUILTIN concat');

            $ai = $this->regIndex($a);
            $bi = $this->regIndex($b);
            $di = $this->regIndex($dstReg);

            //return "  r[$di] = soul_path_join((const char*)r[$ai], (const char*)r[$bi]);";
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_concat((const char*)r[$ai], (const char*)r[$bi]));";


        }

        if ($name === 'append') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: append expects 2 args */';
            }

            [$path, $text] = $argsOperands;

            $this->assertReg($path, 'CALL_BUILTIN append');
            $this->assertReg($text, 'CALL_BUILTIN append');

            $pi = $this->regIndex($path);
            $ti = $this->regIndex($text);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_append((const char*)r[$pi], (const char*)r[$ti]);";
            }

            return "  soul_append((const char*)r[$pi], (const char*)r[$ti]);";
        }

        if ($name === 'stat') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: stat expects 1 arg and returns statinfo */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN stat');

            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_stat((const char*)r[$pi]);";
        }

        if ($name === 'argc') {
            if ($dstReg === null) {
                return '  /* ERROR: argc must return int */';
            }

            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_argc_fn();";
        }

        if ($name === 'argv') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: argv expects 1 arg and returns str */';
            }

            [$idx] = $argsOperands;
            $this->assertReg($idx, 'CALL_BUILTIN argv');

            $ii = $this->regIndex($idx);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_argv_fn((int64_t)r[$ii]);";
        }

        if ($name === 'arg') {
            $argc = count($argsOperands);

            if ($argc < 1 || $argc > 2 || $dstReg === null) {
                return '  /* ERROR: arg expects 1 or 2 args and returns str */';
            }

            $this->assertReg($argsOperands[0], 'CALL_BUILTIN arg');
            $nameIdx = $this->regIndex($argsOperands[0]);

            if ($argc === 2) {
                $this->assertReg($argsOperands[1], 'CALL_BUILTIN arg');
                $defIdx = $this->regIndex($argsOperands[1]);
                $di = $this->regIndex($dstReg);

                return "  r[$di] = soul_arg((const char*)r[$nameIdx], (const char*)r[$defIdx]);";
            }

            // sin default
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_arg((const char*)r[$nameIdx], \"\");";
        }

        if ($name === 'buildstr') {
            if ($dstReg === null) {
                return '  /* ERROR: buildstr must return value */';
            }

            $di = $this->regIndex($dstReg);
            $argc = count($argsOperands);

            // generar array inline SIEMPRE antes de uso
            $args = [];
            foreach ($argsOperands as $arg) {
                $this->assertReg($arg, 'CALL_BUILTIN buildstr');
                $args[] = 'r[' . $this->regIndex($arg) . ']';
            }

            return '  { uintptr_t __tmp[' . $argc . '] = { ' .
                implode(', ', $args) .
                ' }; r[' . $di . '] = soul_buildstr(' . $argc . ', __tmp); }';
        }

        if ($name === 'json_encode') {
            if ($dstReg === null) {
                return '  /* ERROR: json_encode must return str */';
            }

            $argc = count($argsOperands);
            if ($argc % 2 !== 0) {
                return '  /* ERROR: json_encode expects even number of args */';
            }

            $di = $this->regIndex($dstReg);

            $args = [];
            foreach ($argsOperands as $i => $arg) {
                $this->assertReg($arg, 'CALL_BUILTIN json_encode');
                $ri = $this->regIndex($arg);

                // índices impares = values
                if (($i & 1) === 1 && $this->regIsIntValue($program, $ip, $arg)) {
                    $args[] = 'SOUL_TAG_INT((int64_t)r[' . $ri . '])';
                } else {
                    $args[] = 'r[' . $ri . ']';
                }
            }

            return
                '  { uintptr_t __args[' . $argc . '] = { ' .
                implode(', ', $args) .
                ' }; r[' . $di . '] = soul_json_encode(' . $argc . ', __args); }';
        }

        if ($name === 'json_pair_str' || $name === 'json_pair_int' || $name === 'json_object') {
            if ($dstReg === null) {
                return '  /* ERROR: ' . $name . ' must return str */';
            }

            $argc = count($argsOperands);
            $di = $this->regIndex($dstReg);

            $args = [];
            foreach ($argsOperands as $arg) {
                $this->assertReg($arg, 'CALL_BUILTIN ' . $name);
                $args[] = 'r[' . $this->regIndex($arg) . ']';
            }

            return
                '  { uintptr_t __args[' . $argc . '] = { ' .
                implode(', ', $args) .
                ' }; r[' . $di . '] = soul_' . $name . '(' . $argc . ', __args); }';
        }





        if ($name === 'workerId') {
            if ($dstReg === null) {
                return '  /* ERROR: workerId must return int */';
            }

            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_worker_id_fn();";
        }

        if ($name === 'workerCount') {
            if ($dstReg === null) {
                return '  /* ERROR: workerCount must return int */';
            }

            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_worker_count_fn();";
        }

        if ($name === 'atomicCounter') {
            // atomicCounter(name: str) -> int
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: atomicCounter expects 1 arg and returns int */';
            }

            [$arg] = $argsOperands;
            $this->assertReg($arg, 'CALL_BUILTIN atomicCounter');
            $ai = $this->regIndex($arg);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_atomic_counter((const char*)r[$ai]);";
        }

        if ($name === 'atomicAppend') {
            // atomicAppend(path: str, text: str) -> bool
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: atomicAppend expects 2 args */';
            }

            [$path, $text] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN atomicAppend');
            $this->assertReg($text, 'CALL_BUILTIN atomicAppend');

            $pi = $this->regIndex($path);
            $ti = $this->regIndex($text);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_atomic_append((const char*)r[$pi], (const char*)r[$ti]);";
            }

            return "  soul_atomic_append((const char*)r[$pi], (const char*)r[$ti]);";
        }
        
        if ($name === 'isfile') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: isfile expects 1 arg and returns bool */';
            }

            [$stat] = $argsOperands;
            $this->assertReg($stat, 'CALL_BUILTIN isfile');

            $si = $this->regIndex($stat);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_isfile(r[$si]);";
        }

        if ($name === 'isdir') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                return '  /* ERROR: isdir expects 1 arg and returns bool */';
            }

            [$stat] = $argsOperands;
            $this->assertReg($stat, 'CALL_BUILTIN isdir');

            $si = $this->regIndex($stat);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_isdir(r[$si]);";
        }

        if ($name === 'removeTree') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: removeTree expects 1 arg */';
            }

            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN removeTree');
            $pi = $this->regIndex($path);

            if ($dstReg !== null) {
                $di = $this->regIndex($dstReg);
                return "  r[$di] = soul_removeTree((const char*)r[$pi]);";
            }

            return "  soul_removeTree((const char*)r[$pi]);";
        }

        if ($name === 'removeByExt') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: removeByExt expects 2 args */';
            }

            [$path, $ext] = $argsOperands;

            $this->assertReg($path, 'CALL_BUILTIN removeByExt');
            $this->assertReg($ext,  'CALL_BUILTIN removeByExt');

            $pi = $this->regIndex($path);
            $ei = $this->regIndex($ext);

            if ($dstReg !== null) {
                $di = $this->regIndex($dstReg);
                return "  r[$di] = soul_removeByExt((const char*)r[$pi], (const char*)r[$ei]);";
            }

            return "  soul_removeByExt((const char*)r[$pi], (const char*)r[$ei]);";
        }

        if ($name === 'removeOlderThan') {
            [$path, $sec] = $argsOperands;
            $pi = $this->regIndex($path);
            $si = $this->regIndex($sec);
            return "  soul_removeOlderThan((const char*)r[$pi], (int64_t)r[$si]);";
        }

        if ($name === 'removeBiggerThan') {
            [$path, $sz] = $argsOperands;
            $pi = $this->regIndex($path);
            $si = $this->regIndex($sz);
            return "  soul_removeBiggerThan((const char*)r[$pi], (int64_t)r[$si]);";
        }        

        if ($name === 'http_get') {
            // http_get(ip, port, path) -> netconn
            if (count($argsOperands) !== 3 || $dstReg === null) {
                return '  /* ERROR: http_get expects 3 args and returns netconn */';
            }

            [$ip, $port, $path] = $argsOperands;

            $this->assertReg($ip,   'CALL_BUILTIN http_get');
            $this->assertReg($port, 'CALL_BUILTIN http_get');
            $this->assertReg($path, 'CALL_BUILTIN http_get');

            $ai = $this->regIndex($ip);
            $pi = $this->regIndex($port);
            $ti = $this->regIndex($path);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_http_get((const char*)r[$ai], (int)r[$pi], (const char*)r[$ti]);";
        }

        if ($name === 'net_read') {
            // net_read(conn, maxBytes) -> str
            if (count($argsOperands) !== 2 || $dstReg === null) {
                return '  /* ERROR: net_read expects 2 args and returns str */';
            }

            [$conn, $max] = $argsOperands;

            $this->assertReg($conn, 'CALL_BUILTIN net_read');
            $this->assertReg($max,  'CALL_BUILTIN net_read');

            $ci = $this->regIndex($conn);
            $mi = $this->regIndex($max);
            $di = $this->regIndex($dstReg);

            return "  r[$di] = soul_net_read(r[$ci], (int64_t)r[$mi]);";
        }

        if ($name === 'net_close') {
            // net_close(conn)
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: net_close expects 1 arg */';
            }

            [$conn] = $argsOperands;

            $this->assertReg($conn, 'CALL_BUILTIN net_close');
            $ci = $this->regIndex($conn);

            return "  soul_net_close(r[$ci]);";
        }

        if ($name === 'write') {
            if (count($argsOperands) !== 2) {
                throw new RuntimeException("CEmitter: builtin write expects 2 args, got " . count($argsOperands));
            }

            [$a0, $text] = $argsOperands;
            $this->assertReg($a0, 'CALL_BUILTIN write');
            $this->assertReg($text, 'CALL_BUILTIN write');

            $a0i = $this->regIndex($a0);
            $ti  = $this->regIndex($text);

            // Caso A: write(fd, text)  -> soul_write(fd, text)
            if ($this->regIsWriteHandle($program, $ip, $a0, $openHandleVars)) {
                if ($dstReg !== null) {
                    // soul_write no devuelve bool; si el IR espera valor, devuelve 1 "OK" por convenio.
                    $di = $this->regIndex($dstReg);
                    return implode("\n", [
                        "  soul_write(r[$a0i], (const char*)r[$ti]);",
                        "  r[$di] = 1;"
                    ]);
                }

                return "  soul_write(r[$a0i], (const char*)r[$ti]);";
            }

            // Caso B: write(path, text) -> soul_write_file(path, text)
            if ($dstReg !== null) {
                $di = $this->regIndex($dstReg);
                return "  r[$di] = soul_write_file((const char*)r[$a0i], (const char*)r[$ti]);";
            }

            return "  soul_write_file((const char*)r[$a0i], (const char*)r[$ti]);";
        }

        if ($name === 'open') {
            if (count($argsOperands) !== 1 || $dstReg === null) {
                throw new RuntimeException("CEmitter: builtin open expects 1 arg and returns handle");
            }
            [$path] = $argsOperands;
            $this->assertReg($path, 'CALL_BUILTIN open');
            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_open((const char*)r[$pi]);";
        }

        if ($name === 'close') {
            if (count($argsOperands) !== 1) {
                throw new RuntimeException("CEmitter: builtin close expects 1 arg");
            }
            [$h] = $argsOperands;
            $this->assertReg($h, 'CALL_BUILTIN close');
            $hi = $this->regIndex($h);
            return "  soul_close(r[$hi]);";
        }   

        if ($name === 'copy') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: copy expects 2 args */';
            }

            [$src, $dst] = $argsOperands;
            $this->assertReg($src, 'CALL_BUILTIN copy');
            $this->assertReg($dst, 'CALL_BUILTIN copy');

            $si = $this->regIndex($src);
            $di = $this->regIndex($dst);

            // Si el resultado se usa, lo guardamos; si no, lo ignoramos.
            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_copy((const char*)r[$si], (const char*)r[$di]);";
            }

            return "  soul_copy((const char*)r[$si], (const char*)r[$di]);";
        }

        if ($name === 'copyFolder') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: copyFolder expects 2 args */';
            }

            [$src, $dst] = $argsOperands;
            $this->assertReg($src, 'CALL_BUILTIN copyFolder');
            $this->assertReg($dst, 'CALL_BUILTIN copyFolder');

            $si = $this->regIndex($src);
            $di = $this->regIndex($dst);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_copyFolder((const char*)r[$si], (const char*)r[$di]);";
            }

            return "  soul_copyFolder((const char*)r[$si], (const char*)r[$di]);";
        }        

        if ($name === 'backup') {
            if (count($argsOperands) !== 2) {
                return '  /* ERROR: backup expects 2 args */';
            }

            [$src, $dst] = $argsOperands;
            $this->assertReg($src, 'CALL_BUILTIN backup');
            $this->assertReg($dst, 'CALL_BUILTIN backup');

            $si = $this->regIndex($src);
            $di = $this->regIndex($dst);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_copyFolder((const char*)r[$si], (const char*)r[$di]);";
            }

            return "  soul_copyFolder((const char*)r[$si], (const char*)r[$di]);";
        }


        if ($name === 'dir_close') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: dir_close expects 1 arg */';
            }
            $arg = $argsOperands[0]; $this->assertReg($arg, 'CALL_BUILTIN dir_close');
            $ai = $this->regIndex($arg);
            return "  soul_dir_close(r[$ai]);";
        }        

        // Builtins aún no soportados en C emitter
        // (exists/listdir/copy...) vendrán cuando bajemos a syscalls o libc.
        return '  /* TODO builtin: ' . $name . ' */';
    }

    private function emitUnaryCall(string $fn, array $args, IrReg $dst): string
    {
        $a0 = $this->regIndex($args[0]);
        $di = $this->regIndex($dst);

        return "  r[$di] = (uintptr_t)$fn(r[$a0]);";
    }



    private function emitBinaryCall(string $fn, array $args, IrReg $dst): string
    {
        $a0 = $this->regIndex($args[0]);
        $a1 = $this->regIndex($args[1]);
        $di = $this->regIndex($dst);

        return "  r[$di] = (uintptr_t)$fn(r[$a0], r[$a1]);";
    }



    private function emitExit(array $ops): string
    {
        if (empty($ops)) {
            return '  return 0;';
        }

        $exitReg = $ops[0];
        $this->assertReg($exitReg, 'EXIT');
        $ri = $this->regIndex($exitReg);

        return '  return (int)r[' . $ri . '];';
    }

    private function programUsesRuntimePrelude(IrProgram $program): bool
    {
        foreach ($program->instructions as $inst) {
            if ($inst->opCode !== OpCode::CALL_BUILTIN) continue;

            $nameOp = $inst->operands[0] ?? null;
            if (!($nameOp instanceof IrLit) || !is_string($nameOp->value)) continue;

            if (in_array($nameOp->value, [
                'listdir','dir_next','dir_close', 'rmdir',
                'join','concat','slice',
                'endswith','startswith','length',
                'copy','mkdir','append', 'copyFolder', 'backup', 'move',
                'stat','isfile','isdir','http_get', 'net_read', 'net_close',
                'open','close', 'remove', 'mtime', 'buildstr', 'mkdir',
                'workerId', 'workerCount', 'atomicCounter', 'atomicAppend',
                'argc', 'argv', 'arg', 'toStr', 'toInt', 'removeTree', 'overview', 'overviewSummary', 'now',
                'walk', 'walk_next', 'walk_close', 'json_encode',
                'analyze', 'analyzeFiles', 'analyzeDirs', 'analyzeSize',
                'analyzeExtCount', 'analyzeExtName', 'analyzeExtFiles', 'analyzeExtSize',
                'analyzeTopCount', 'analyzeTopPath', 'analyzeTopSize',
            ], true)) return true;

        }
        return false;
    }

    private function programUsesParallel(IrProgram $program): bool
    {
        foreach ($program->instructions as $inst) {
            if ($inst->opCode !== OpCode::CALL_BUILTIN) continue;

            $nameOp = $inst->operands[0] ?? null;
            if (!($nameOp instanceof IrLit)) continue;

            if ($nameOp->value === 'parallelRun' || $nameOp->value === 'parallelWait') {
                return true;
            }
        }
        return false;
    }


    // --------------------------------------------------
    // Symbol collection
    // --------------------------------------------------

    /**
     * @return array{0: array<string,true>, 1: array<string,true>}
     */
    private function collectSymbols(IrProgram $program): array
    {
        $regs = [];
        $vars = [];

        foreach ($program->instructions as $inst) {
            // Registros y variables en operandos
            foreach ($inst->operands as $op) {
                if ($op instanceof IrReg) {
                    $regs[$op->name] = true;
                } elseif ($op instanceof IrVar) {
                    $vars[$op->name] = true;
                }
            }

            // Además: STORE_VAR y LOAD_VAR garantizan variable
            if ($inst->opCode === OpCode::STORE_VAR) {
                $var = $inst->operands[1] ?? null;
                if ($var instanceof IrVar) {
                    $vars[$var->name] = true;
                }
            }

            if ($inst->opCode === OpCode::LOAD_VAR) {
                $var = $inst->operands[0] ?? null;
                if ($var instanceof IrVar) {
                    $vars[$var->name] = true;
                }
            }
        }

        return [$regs, $vars];
    }

    private function regIsIntValue(IrProgram $program, int $ip, IrReg $reg): bool
    {
        $target = $reg->name;
        $insts = $program->instructions;

        // Builtins que DEVUELVEN int/bool (deben taggearse si se pasan a json_encode)
        $intReturningBuiltins = [
            'mtime', 'length', 'toInt', 'argc',
            'workerId', 'workerCount',
            'atomicCounter',
            'isfile', 'isdir',
            // si tienes más builtins que devuelven int/bool, añádelos aquí
        ];

        for ($j = $ip - 1; $j >= 0; $j--) {
            $inst = $insts[$j];

            // Definición por LOAD_CONST
            if ($inst->opCode === OpCode::LOAD_CONST) {
                [$lit, $dst] = $inst->operands;
                if ($dst instanceof IrReg && $dst->name === $target && $lit instanceof IrLit) {
                    return is_int($lit->value) || is_bool($lit->value);
                }
            }

            // Definición por operaciones aritméticas / comparaciones (siempre int/bool en tu backend)
            if (in_array($inst->opCode, [
                OpCode::ADD, OpCode::SUB, OpCode::MUL, OpCode::DIV, OpCode::MOD,
                OpCode::CMP_LT, OpCode::CMP_LE, OpCode::CMP_GT, OpCode::CMP_GE, OpCode::CMP_EQ, OpCode::CMP_NE,
                OpCode::NEG, OpCode::NOT,
            ], true)) {
                $dst = $inst->operands[2] ?? ($inst->operands[1] ?? null); // unary usa [src,dst]
                if ($dst instanceof IrReg && $dst->name === $target) {
                    return true;
                }
            }

            // Definición por CALL_BUILTIN
            if ($inst->opCode === OpCode::CALL_BUILTIN) {
                $nameOp = $inst->operands[0] ?? null;
                if (!($nameOp instanceof IrLit) || !is_string($nameOp->value)) continue;

                $maybeDst = $inst->operands[count($inst->operands) - 1] ?? null;
                if ($maybeDst instanceof IrReg && $maybeDst->name === $target) {
                    return in_array($nameOp->value, $intReturningBuiltins, true);
                }
            }

            // Si el registro se reescribe con LOAD_VAR, no sabemos su tipo -> no tag
            if ($inst->opCode === OpCode::LOAD_VAR) {
                [$var, $dst] = $inst->operands;
                if ($dst instanceof IrReg && $dst->name === $target) {
                    return false;
                }
            }
        }

        return false;
    }


    private function regComesFromOpen(IrProgram $program, IrReg $reg): bool
    {
        $target = $reg->name;

        foreach ($program->instructions as $inst) {
            if ($inst->opCode !== OpCode::CALL_BUILTIN) continue;

            $nameOp = $inst->operands[0] ?? null;
            if (!($nameOp instanceof IrLit) || $nameOp->value !== 'open') continue;

            // Estructura: CALL_BUILTIN <lit('open')> <argReg> <dstReg>
            $ops = $inst->operands;
            $maybeDst = $ops[count($ops) - 1] ?? null;

            if ($maybeDst instanceof IrReg && $maybeDst->name === $target) {
                return true;
            }
        }

        return false;
    }

    private function maxRegIndex(array $regs): int
    {
        $max = 0;
        foreach ($regs as $name => $_) {
            if (preg_match('/^r(\d+)$/', $name, $m)) {
                $idx = (int)$m[1];
                if ($idx > $max) $max = $idx;
            }
        }
        return $max;
    }

    private function regIsIntLike($reg): bool {
        $t = $reg->type ?? null;
        return $t === 'int' || $t === 'bool';
    }


    // --------------------------------------------------
    // C formatting helpers
    // --------------------------------------------------

    private function regIndex(IrReg $reg): int
    {
        if (!preg_match('/^r(\d+)$/', $reg->name, $m)) {
            throw new RuntimeException("CEmitter: Registro inválido: " . $reg->name);
        }
        return (int)$m[1];
    }

    private function collectOpenHandleVars(IrProgram $program): array
    {
        $vars = [];

        $insts = $program->instructions;
        $n = count($insts);

        for ($i = 0; $i < $n; $i++) {
            $inst = $insts[$i];

            if ($inst->opCode !== OpCode::CALL_BUILTIN) continue;

            $nameOp = $inst->operands[0] ?? null;
            if (!($nameOp instanceof IrLit) || $nameOp->value !== 'open') continue;

            // open(path) devuelve en el último operando (dstReg)
            $dstReg = $inst->operands[count($inst->operands) - 1] ?? null;
            if (!($dstReg instanceof IrReg)) continue;

            // patrón típico: CALL_BUILTIN open ... <dstReg>  seguido de STORE_VAR <dstReg> <$var>
            if ($i + 1 < $n) {
                $next = $insts[$i + 1];
                if ($next->opCode === OpCode::STORE_VAR) {
                    [$src, $var] = $next->operands;
                    if ($src instanceof IrReg && $var instanceof IrVar && $src->name === $dstReg->name) {
                        $vars[$var->name] = true;
                    }
                }
            }
        }

        return $vars; // set de nombres de variable
    }

    private function regIsWriteHandle(IrProgram $program, int $ip, IrReg $reg, array $openHandleVars): bool
    {
        $target = $reg->name;
        $insts = $program->instructions;

        for ($j = $ip - 1; $j >= 0; $j--) {
            $inst = $insts[$j];

            // LOAD_VAR <$var> <dstReg>
            if ($inst->opCode === OpCode::LOAD_VAR) {
                [$var, $dst] = $inst->operands;
                if ($dst instanceof IrReg && $dst->name === $target && $var instanceof IrVar) {
                    return isset($openHandleVars[$var->name]);
                }
            }

            // CALL_BUILTIN open ... <dstReg>
            if ($inst->opCode === OpCode::CALL_BUILTIN) {
                $nameOp = $inst->operands[0] ?? null;
                if ($nameOp instanceof IrLit && $nameOp->value === 'open') {
                    $dst = $inst->operands[count($inst->operands) - 1] ?? null;
                    if ($dst instanceof IrReg && $dst->name === $target) {
                        return true;
                    }
                }
            }

            // LOAD_CONST <lit> <dstReg> (si pisa el registro, ya no es handle)
            if ($inst->opCode === OpCode::LOAD_CONST) {
                [$lit, $dst] = $inst->operands;
                if ($dst instanceof IrReg && $dst->name === $target) {
                    return false;
                }
            }

            // Operaciones que escriben dst (ADD/SUB/.../CMP/... etc) si pisan target => ya no es handle
            // (si quieres, añade más casos; con esto suele bastar)
            if (in_array($inst->opCode, [OpCode::ADD,OpCode::SUB,OpCode::MUL,OpCode::DIV,OpCode::MOD,
                                        OpCode::CMP_LT,OpCode::CMP_LE,OpCode::CMP_GT,OpCode::CMP_GE,OpCode::CMP_EQ,OpCode::CMP_NE], true)) {
                $dst = $inst->operands[2] ?? null;
                if ($dst instanceof IrReg && $dst->name === $target) {
                    return false;
                }
            }
        }

        return false;
    }

    private function cVar(string $name): string
    {
        // El IR debería venir sin '$', pero por si acaso:
        if (str_starts_with($name, '$')) {
            $name = substr($name, 1);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        if ($safe === '') {
            $safe = 'v';
        }

        // Si empieza por dígito, prefijo fijo
        if (preg_match('/^[0-9]/', $safe)) {
            $safe = 'v_' . $safe;
        }

        return $safe;
    }


    private function cLiteral(mixed $value): string
    {
        if (is_int($value)) return (string)$value;
        if (is_float($value)) return (string)$value;
        if (is_bool($value)) return $value ? '1' : '0';
        if ($value === null) return '0';

        if (is_string($value)) {
            // escapado mínimo
            $escaped = addcslashes($value, "\\\"\n\r\t");
            $escaped = str_replace(["\n", "\r", "\t"], ["\\n", "\\r", "\\t"], $escaped);
            return '"' . $escaped . '"';
        }

        // fallback
        return '0';
    }

    // --------------------------------------------------
    // Assertions
    // --------------------------------------------------

    private function assertReg($op, string $ctx): void
    {
        if (!($op instanceof IrReg)) {
            throw new RuntimeException("CEmitter: Se esperaba registro en " . $ctx);
        }
    }

    private function assertVar($op, string $ctx): void
    {
        if (!($op instanceof IrVar)) {
            throw new RuntimeException("CEmitter: Se esperaba variable en " . $ctx);
        }
    }

    private function assertLit($op, string $ctx): void
    {
        if (!($op instanceof IrLit)) {
            throw new RuntimeException("CEmitter: Se esperaba literal en " . $ctx);
        }
    }

    private function assertLabel($op, string $ctx): void
    {
        if (!($op instanceof IrLabelRef)) {
            throw new RuntimeException("CEmitter: Se esperaba label en " . $ctx);
        }
    }
}
