# Soul — v0.1

Soul es un lenguaje de scripting **minimalista y de bajo nivel**, orientado a **operaciones de sistema y filesystem**, diseñado para ser **explícito, legible y muy eficiente** en tareas prácticas como:

- copias masivas de ficheros
- backups y sincronización
- limpieza de árboles de directorios
- procesamiento intensivo de filesystem
- tooling de sistemas

Soul no pretende ser un lenguaje generalista.  
Es una herramienta para **hacer bien trabajos duros**, de forma predecible y controlada.

---

## Principios de diseño

### 1. Simplicidad explícita

Soul evita inferencias ocultas y comportamientos mágicos.  
El código debe decir claramente lo que hace.

```soul
int x = 5
```

es preferible a inferencias implícitas difíciles de razonar.

---

### 2. El filesystem es de primera clase

Soul está diseñado desde el inicio para trabajar con archivos y directorios.  
No como una librería añadida, sino como parte central del lenguaje.

```soul
diriter it = listdir(src)

while true:
    str name = dir_next(it)
    if name == 0:
        break

    copy(concat(src, name), concat(dst, name))

dir_close(it)
```

El filesystem no es una API externa: es parte del modelo mental del lenguaje.

---

### 3. Control total del flujo

Soul evita excepciones implícitas y flujos ocultos.  
El control de errores y decisiones es **lineal, visible y legible**.

---

### 4. Paralelismo como intención

Soul introduce **paralelismo estructural**:

```soul
parallel 4:
    copyFolder(src, dst)
```

En v0.1, `parallel` **no ejecuta en paralelo real**.  
Expresa intención y estructura, preparando el código para futuras ejecuciones concurrentes.

---

### 5. Sintaxis pequeña y estable

Soul evita crecer de forma arbitraria.  
La sintaxis se congela pronto y solo se amplía cuando aporta valor real.

---

## Características principales (v0.1)

- Sintaxis basada en indentación
- Tipado explícito
- Control de flujo clásico (`if`, `while`, `for`)
- Compilación a C
- Runtime propio basado en syscalls
- Iteración eficiente de directorios
- Operaciones de filesystem de alto rendimiento
- Feedback de progreso integrado
- Preparado para paralelismo real

---

## Tipos soportados (v0.1)

| Tipo | Descripción |
|------|-------------|
| int | Entero |
| bool | Booleano |
| str | Cadena de texto (incluye rutas) |
| diriter | Iterador de directorio |

---

## Estado del proyecto

- Lexer estable
- Parser estable
- Analyzer funcional
- Compilación a C estable
- Runtime propio
- Sintaxis congelada (v0.1)

---

Soul es una herramienta de **sistemas**, no un lenguaje generalista.
