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

        // Variables: igual, palabra máquina (path/str necesitan puntero)
        foreach (array_keys($vars) as $v) {
            $out[] = '  uintptr_t ' . $this->cVar($v) . ' = 0;';
        }

        $out[] = '';

        // Emitir instrucciones (con peephole simple)
        $insts = $program->instructions;
        $cnt = count($insts);

        for ($i = 0; $i < $cnt; $i++) {
            $inst = $insts[$i];

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
            $line = $this->emitInstruction($inst);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        $out[] = '  return 0;';
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
            '      return (uintptr_t)(i + 1);',
            '    }',
            '  }',
            '  return (uintptr_t)0;',
            '}',
            '',
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
'#define SOUL_STR_POOL_SIZE (1 << 20)  // 1 MiB por proceso (ajustable)',
'',
'static char soul_str_pool[SOUL_STR_POOL_SIZE];',
'static int soul_str_pool_pos = 0;',
'',
'static const char* soul_persist_str(const char* s) {',
'    if (!s) s = "";',
'',
'    int len = 0;',
'    while (s[len]) len++;',
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
'static uintptr_t soul_buildstr(int count, uintptr_t* parts) {',
'    char tmp[4096];',
'    int pos = 0;',
'',
'    for (int i = 0; i < count; i++) {',
'        const char* s = (const char*)parts[i];',
'        if (!s) continue;',
'        for (int j = 0; s[j] && pos < 4095; j++) {',
'            tmp[pos++] = s[j];',
'        }',
'    }',
'',
'    tmp[pos] = 0;',
'',
'    // 🔒 devolver string persistente',
'    return (uintptr_t)soul_persist_str(tmp);',
'}',
'',
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
'static int soul_copyFolder_depth = 0;',            
'',
'static uintptr_t soul_copy(const char* src, const char* dst) {',
'  if (!src || !dst) return 0;',
'',
'  int in = (int)syscall(SYS_openat, AT_FDCWD, src, O_RDONLY, 0);',
'  if (in < 0) return 0;',
'',
'  int out = (int)syscall(SYS_openat, AT_FDCWD, dst, O_WRONLY | O_CREAT | O_TRUNC, 0644);',
'  if (out < 0) {',
'    syscall(SYS_close, in);',
'    return 0;',
'  }',
'',
'  char buf[1 << 16];',
'  for (;;) {',
'    long n = syscall(SYS_read, in, buf, sizeof(buf));',
'    if (n == 0) break;',
'    if (n < 0) {',
'      syscall(SYS_close, in);',
'      syscall(SYS_close, out);',
'      return 0;',
'    }',
'',
'    // progress durante copia de bloques grandes',
'    if (soul_copyFolder_depth == 1) {',
'      soul_progress_tick();',
'    }',
'',
'    char* p = buf;',
'    long left = n;',
'    while (left > 0) {',
'      long w = syscall(SYS_write, out, p, (size_t)left);',
'      if (w <= 0) {',
'        syscall(SYS_close, in);',
'        syscall(SYS_close, out);',
'        return 0;',
'      }',
'      p += w;',
'      left -= w;',
'    }',
'  }',
'',
'  syscall(SYS_close, in);',
'  syscall(SYS_close, out);',
'  return 1;',
'}',
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
'  remove_depth--;',
'  if (is_root) soul_progress_end(1);',
'',
'  return 1;',
'}',
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
            '',     
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
'  int idx = (int)h - 1;',
'  if (idx < 0 || idx >= 8) return (uintptr_t)0;',
'  dir_iter_t* it = &iters[idx];',
'  if (it->fd < 0) return (uintptr_t)0;',
'',
'  for (;;) {',
'    if (it->bpos >= it->nread) {',
'      it->nread = (int)syscall(SYS_getdents64, it->fd, it->buf, sizeof(it->buf));',
'      it->bpos = 0;',
'      if (it->nread <= 0) {',
'        return (uintptr_t)0;',
'      }',
'    }',
'',
'    struct linux_dirent64* d = (struct linux_dirent64*)(it->buf + it->bpos);',
'    it->bpos += d->d_reclen;',
'',
"    if (d->d_name[0] == '.' && (d->d_name[1] == 0 || (d->d_name[1] == '.' && d->d_name[2] == 0))) {",
'      continue;',
'    }',
'',
'    if (d->d_reclen == 0) {',
'      return (uintptr_t)0;',
'    }',
'',
'char* out = dir_name_bufs[dir_name_idx++ & 15];',
'',
'// copiar nombre',
'int i = 0;',
'while (d->d_name[i] && i < MAX_DIRENT_NAME - 1) {',
'    out[i] = d->d_name[i];',
'    i++;',
'}',
'out[i] = 0;',
'',
'return (uintptr_t)out;',
'  }',
'}',
'',
            


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
'',
'static void soul_worker_init(void) {',
'    if (soul_worker_inited) return;',
'    const char* sid = getenv("SOUL_WORKER_ID");',
'    const char* sc  = getenv("SOUL_WORKER_COUNT");',
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
'    if (soul_worker_count > 1) return;',
'',
'    // Parent (worker 0) lanza N-1 hijos',
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
'    for (int i = 1; i < workers; i++) {',
'        int status = 0;',
'        wait(&status);',
'    }',
'}',
'',
'static uintptr_t soul_copyFolder(const char* src, const char* dst) {',
'  if (!src || !dst) return 0;',
'',
'  soul_copyFolder_depth++;',
'  int is_root = (soul_copyFolder_depth == 1 && soul_worker_id == 0);',
'  if (is_root) soul_progress_begin("copy");',
'',
'  struct stat st;',
'  if (stat(src, &st) != 0 || !S_ISDIR(st.st_mode)) {',
'    soul_copyFolder_depth--;',
'    if (is_root) soul_progress_end(0);',
'    return 0;',
'  }',
'',
'  // crear destino si no existe',
'  syscall(SYS_mkdirat, AT_FDCWD, dst, 0755);',
'',
'  int fd = (int)syscall(SYS_openat, AT_FDCWD, src, O_RDONLY | O_DIRECTORY, 0);',
'  if (fd < 0) {',
'    soul_copyFolder_depth--;',
'    if (is_root) soul_progress_end(0);',
'    return 0;',
'  }',
'',
'  char buf[1 << 16];',
'',
'  for (;;) {',
'    int nread = (int)syscall(SYS_getdents64, fd, buf, sizeof(buf));',
'    if (nread <= 0) break;',
'',
'    int bpos = 0;',
'    while (bpos < nread) {',
'      if (is_root) soul_progress_tick();',
'',
'      struct linux_dirent64* d = (struct linux_dirent64*)(buf + bpos);',
'      bpos += d->d_reclen;',
'',
'      if (!d->d_name[0] ||',
'          (d->d_name[0] == \'.\' &&',
'          (!d->d_name[1] || (d->d_name[1] == \'.\' && !d->d_name[2])))) {',
'        continue;',
'      }',
'',
'      char srcPath[4096];',
'      char dstPath[4096];',
'',
'      snprintf(srcPath, sizeof(srcPath), "%s/%s", src, d->d_name);',
'      snprintf(dstPath, sizeof(dstPath), "%s/%s", dst, d->d_name);',
'',
'      if (stat(srcPath, &st) != 0) continue;',
'',
'      if (S_ISDIR(st.st_mode)) {',
'        soul_copyFolder(srcPath, dstPath);',
'      } else if (S_ISREG(st.st_mode)) {',
'        soul_copy(srcPath, dstPath);',
'      }',
'    }',
'  }',
'',
'  syscall(SYS_close, fd);',
'',
'  soul_copyFolder_depth--;',
'  if (is_root) soul_progress_end(1);',
'',
'  return 1;',
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


        ];
    }



    // --------------------------------------------------
    // Instruction emission
    // --------------------------------------------------

    private function emitInstruction(IrInstruction $inst): string
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
            OpCode::CALL_BUILTIN => $this->emitCallBuiltin($ops),

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

    private function emitCallBuiltin(array $ops): string
    {
        // CALL_BUILTIN <lit(name)> <argReg>* [<dstReg>]
        $nameOp = $ops[0] ?? null;
        $this->assertLit($nameOp, 'CALL_BUILTIN');
        $name = $nameOp->value;

        if (!is_string($name)) {
            return '  /* ERROR: builtin name not string */';
        }

        $argsOperands = array_slice($ops, 1);

        $voidBuiltins = [
            'print', 'printStr',
            'dir_close', 'net_close',
            'move', 'mkdir', 'append', 'copy',
            'write', 'close', 'remove', 'parallelRun', 'parallelWait',
            'copyFolder',
        ];



        $dstReg = null;

        if (!in_array($name, $voidBuiltins, true)) {
            if (!empty($argsOperands) && end($argsOperands) instanceof IrReg) {
                $dstReg = array_pop($argsOperands);
            }
        }

        if ($name === 'parallelRun') {
            if (count($argsOperands) !== 1) return '  /* ERROR: parallelRun expects 1 arg */';
            $this->assertReg($argsOperands[0], 'CALL_BUILTIN parallelRun');
            $wi = $this->regIndex($argsOperands[0]);
            return '  soul_parallel_run((int)r[' . $wi . ']);';
        }

        if ($name === 'parallelWait') {
            if (count($argsOperands) !== 1) return '  /* ERROR: parallelWait expects 1 arg */';
            $this->assertReg($argsOperands[0], 'CALL_BUILTIN parallelWait');
            $wi = $this->regIndex($argsOperands[0]);
            return '  soul_parallel_wait((int)r[' . $wi . ']);';
        }



        // Solo implementamos print en fase 1
        if ($name === 'print') {
            // print(x): por ahora imprimimos como int64
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: print expects 1 arg */';
            }
            $arg = $argsOperands[0];
            $this->assertReg($arg, 'CALL_BUILTIN print');
            $ai = $this->regIndex($arg);

            // printf("%lld\n", (long long)r[ai]);
            return '  printf("%lld\\n", (long long)r[' . $ai . ']);';
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

            //return "  r[$di] = soul_concat((const char*)r[$ai], (const char*)r[$bi]);";
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_concat((const char*)r[$ai], (const char*)r[$bi]));";

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
            return "  { const char* __p = (const char*)soul_dir_next(r[$ai]); r[$di] = (uintptr_t)(__p ? soul_persist_str(__p) : 0); }";

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
            return "  r[$di] = (uintptr_t)soul_persist_str((const char*)soul_path_join((const char*)r[$ai], (const char*)r[$bi]));";

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
            // buildstr(arg1, arg2, ..., argN) → string
            if ($dstReg === null) {
                return '  /* ERROR: buildstr must return value */';
            }

            // argsOperands contiene N registros -> debemos pasarlos a un array temporal en C
            $di = $this->regIndex($dstReg);
            $argc = count($argsOperands);

            // Construimos el array local C: uintptr_t tmpArgs[N] = { r[a], r[b], ... };
            $code  = "  { uintptr_t tmpArgs[$argc];\n";
            
            foreach ($argsOperands as $i => $arg) {
                $this->assertReg($arg, 'CALL_BUILTIN buildstr');
                $ai = $this->regIndex($arg);
                $code .= "    tmpArgs[$i] = r[$ai];\n";
            }

            $code .= "    r[$di] = soul_buildstr($argc, tmpArgs);\n";
            $code .= "  }";

            return $code;
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

        
        if ($name === 'mkdir') {
            if (count($argsOperands) !== 1) {
                return '  /* ERROR: mkdir expects 1 arg */';
            }

            $arg = $argsOperands[0];
            $this->assertReg($arg, 'CALL_BUILTIN mkdir');
            $ai = $this->regIndex($arg);

            if ($dstReg !== null) {
                $ri = $this->regIndex($dstReg);
                return "  r[$ri] = soul_mkdir((const char*)r[$ai]);";
            }

            return "  soul_mkdir((const char*)r[$ai]);";
        }

        if ($name === 'open') {
            [$path] = $argsOperands;
            $pi = $this->regIndex($path);
            $di = $this->regIndex($dstReg);
            return "  r[$di] = soul_open((const char*)r[$pi]);";
        }

        if ($name === 'write') {
            [$h, $text] = $argsOperands;
            $hi = $this->regIndex($h);
            $ti = $this->regIndex($text);
            return "  soul_write(r[$hi], (const char*)r[$ti]);";
        }

        if ($name === 'close') {
            [$h] = $argsOperands;
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
                'copy','mkdir','append', 'copyFolder', 'move',
                'stat','isfile','isdir','http_get', 'net_read', 'net_close',
                'open','write','close', 'remove', 'mtime', 'buildstr',
                'workerId', 'workerCount', 'atomicCounter', 'atomicAppend',
                'argc', 'argv', 'arg', 'toStr', 'toInt', 'removeTree'
            ], true)) return true;

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
