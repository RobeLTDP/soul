Soul — v0.1

Soul es un lenguaje de scripting minimalista orientado a operaciones de sistema y filesystem, diseñado para ser simple, legible y eficiente en tareas prácticas como backups, sincronización de ficheros y procesamiento masivo.

Soul prioriza:

claridad semántica

control explícito

bajo ruido sintáctico

preparación para paralelismo real

Filosofía del lenguaje

Soul se rige por los siguientes principios:

1. Simplicidad explícita

No hay magia implícita ni inferencias ocultas.
Los tipos, el control de flujo y las operaciones son claras y visibles en el código.

int x = 5


es preferible a inferencias ambiguas.

2. El filesystem es de primera clase

Soul está pensado para trabajar con archivos y directorios, no como un añadido posterior.

path[] files = listdir(src)
for path f in files:
    copy(f, dst)


El filesystem no es una API externa: es parte del modelo mental del lenguaje.

3. Control de errores expresivo

Los errores no se esconden en excepciones implícitas.

exists(src) !! stop "Origen no existe"


El flujo de error es visible, lineal y legible.

4. Paralelismo como intención, no como efecto colateral

parallel expresa intención de concurrencia, incluso cuando la implementación aún es secuencial.

parallel 4:
    process(f)


El código no tendrá que cambiar cuando Soul incorpore paralelismo real.

5. Sintaxis pequeña, estable y duradera

Soul evita crecer en direcciones arbitrarias.
La sintaxis se congela pronto y se amplía solo cuando aporta valor real.

Características principales

Sintaxis basada en indentación

Tipado explícito

Control de flujo clásico (if, while, for)

Operador de comprobación (!! stop)

Bloques de ejecución paralela (parallel N:)

Librería estándar orientada a filesystem

Ejecución determinista (v0.1)

Tipos soportados (v0.1)
Tipo	Descripción
int	Entero
bool	Booleano
str	Cadena de texto
path	Ruta de sistema
path[]	Array de rutas
Sintaxis básica
Declaración de variables
int x = 5
path src = "./data"
path[] files = listdir(src)

Condicionales
if x > 3:
    print("mayor")
else:
    print("menor")

Bucles
While
int i = 0

while i < 5:
    print(i)
    i = i + 1

For
for path f in files:
    print(f)

Paralelismo (estructural)
parallel 4:
    for path f in files:
        copy(f, dst)


En v0.1, parallel no ejecuta en paralelo real.
El número indica el grado de paralelismo deseado, que será utilizado por futuras implementaciones concurrentes.

Operador de comprobación (!! stop)

Soul introduce un operador de control de errores expresivo:

exists(src) !! stop "Origen no existe"


Si la expresión es falsa, se ejecuta stop y se aborta la ejecución.

Reglas de scope (contrato del lenguaje)

Estas reglas están congeladas en v0.1.

Construcción	Crea scope	Motivo
if	✔	Bloque condicional
while	✔	Control de flujo
for	✔	Variable iteradora
parallel	❌	Modificador de ejecución
check	❌	Expresión de control
stop	❌	Control de salida

⚠️ Estas reglas no deben cambiarse sin una razón de diseño fuerte.

Librería estándar (v0.1)
Filesystem
Función	Descripción
exists(path)	Comprueba existencia
listdir(path)	Lista ficheros (path[])
copy(src, dst)	Copia fichero
mkdir(path)	Crea directorio
basename(path)	Nombre base
write(path, text)	Escribe / añade texto
Utilidades
Función	Descripción
print(x)	Imprime con salto de línea
len(arr)	Longitud de array
Estado del proyecto

Lexer estable

Parser estable

Analyzer estable

Ejecutor funcional

Sintaxis congelada (v0.1)

Paralelismo estructural preparado

Próximos pasos

Ampliar stdlib de filesystem (read, size, mtime…)

Manipulación de strings

Paralelismo real

Tooling (soul run, soul check)