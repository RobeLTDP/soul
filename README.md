<<<<<<< HEAD
# Soul — v0.1

Soul is a **minimalist, low-level scripting language** focused on **system and filesystem operations**, designed to be **explicit, readable, and highly efficient** for practical tasks such as:

- massive file copying
- backups and synchronization
- directory tree cleanup
- intensive filesystem processing
- systems tooling

Soul is not intended to be a general-purpose language.  
It is a tool to **do hard jobs well**, in a predictable and controlled way.

---

## Design principles

### 1. Explicit simplicity

Soul avoids hidden inference and magical behavior.  
Code must clearly state what it does.

```soul
int x = 5
```

is preferred over implicit behavior that is hard to reason about.

---

### 2. Filesystem as a first-class concept

Soul is designed from the ground up to work with files and directories.  
Not as an added library, but as a core part of the language.

```soul
diriter it = listdir(src)

while true:
    str name = dir_next(it)
    if name == 0:
        break

    copy(concat(src, name), concat(dst, name))

dir_close(it)
```

The filesystem is not an external API: it is part of the language's mental model.

---

### 3. Full control of execution flow

Soul avoids implicit exceptions and hidden control paths.  
Error handling and decision flow are **linear, explicit, and readable**.

The language favors code that can be read as a clear sequence of actions.

---

### 4. Parallelism as intention

Soul introduces **structural parallelism**:

```soul
parallel 4:
    copyFolder(src, dst)
```

In v0.1, `parallel` does **not execute real parallelism**.  
It expresses intent and structure, preparing code for future concurrent execution **without syntactic changes**.

---

### 5. Small and stable syntax

Soul avoids uncontrolled growth.  
Syntax is frozen early and only expanded when it brings clear value.

Stability is an explicit design decision.

---

## Main features (v0.1)

- Indentation-based syntax
- Explicit typing
- Classic control flow (`if`, `while`, `for`)
- Compilation to C
- Custom runtime based on syscalls
- Efficient directory iteration
- High-performance filesystem operations
- Built-in progress feedback for long-running tasks
- Prepared for real parallelism

---

## Supported types (v0.1)

| Type    | Description |
|---------|-------------|
| int     | Integer |
| bool    | Boolean |
| str     | String (including filesystem paths) |
| diriter | Directory iterator |

> In Soul, filesystem paths are represented as `str`.

---

## Basic syntax

### Variables

```soul
int x = 5
str folder = arg("--folder")
```

### Conditionals

```soul
if x > 3:
    printStr("greater")
else:
    printStr("lower")
```

### Loops

#### while

```soul
int i = 0
while i < 5:
    print(i)
    i = i + 1
```

#### for (structural)

```soul
for str f in files:
    printStr(f)
```

---

## Standard library (excerpt)

### Filesystem

| Function | Description |
|---------|-------------|
| listdir(path) | Open directory iterator |
| dir_next(it) | Return next entry |
| dir_close(it) | Close iterator |
| copy(src, dst) | Copy file |
| copyFolder(src, dst) | Recursive directory copy |
| remove(path) | Remove file |
| removeTree(path) | Remove full directory tree |
| mkdir(path) | Create directory |

### Strings and utilities

| Function | Description |
|---------|-------------|
| print(x) | Print integer |
| printStr(s) | Print string |
| concat(a, b) | Concatenate strings |
| startswith(a, b) | Prefix check |
| endswith(a, b) | Suffix check |
| length(s) | Length |

### CLI

| Function | Description |
|---------|-------------|
| arg(name) | Get CLI argument |
| arg(name, def) | Argument with default value |

---

## Tooling

- `soul run` — execute Soul script
- `soul build` — compile to binary
- Custom runtime with no external dependencies
- Real-world examples in `/library`

---

## Project status

- Stable lexer
- Stable parser
- Functional analyzer
- Stable C compilation
- Custom runtime
- Syntax frozen (v0.1)
- Real-world scripts used for large copy and delete operations

Soul is already used to **copy and delete tens of gigabytes** with progress feedback.

---

## Mid-term vision

- Real parallelism
- Execution planning (`soul plan`)
- Binary export
- Extended standard library
- Compilation and distribution platform

Soul is a **systems tool**, not a general-purpose language.
=======
# Soul — v0.1

Soul is a **minimalist, low-level scripting language** focused on **system and filesystem operations**, designed to be **explicit, readable, and highly efficient** for practical tasks such as:

- massive file copying
- backups and synchronization
- directory tree cleanup
- intensive filesystem processing
- systems tooling

Soul is not intended to be a general-purpose language.  
It is a tool to **do hard jobs well**, in a predictable and controlled way.

---

## Design principles

### 1. Explicit simplicity

Soul avoids hidden inference and magical behavior.  
Code must clearly state what it does.

```soul
int x = 5
```

is preferred over implicit behavior that is hard to reason about.

---

### 2. Filesystem as a first-class concept

Soul is designed from the ground up to work with files and directories.  
Not as an added library, but as a core part of the language.

```soul
diriter it = listdir(src)

while true:
    str name = dir_next(it)
    if name == 0:
        break

    copy(concat(src, name), concat(dst, name))

dir_close(it)
```

The filesystem is not an external API: it is part of the language's mental model.

---

### 3. Full control of execution flow

Soul avoids implicit exceptions and hidden control paths.  
Error handling and decision flow are **linear, explicit, and readable**.

The language favors code that can be read as a clear sequence of actions.

---

### 4. Parallelism as intention

Soul introduces **structural parallelism**:

```soul
parallel 4:
    copyFolder(src, dst)
```

In v0.1, `parallel` does **not execute real parallelism**.  
It expresses intent and structure, preparing code for future concurrent execution **without syntactic changes**.

---

### 5. Small and stable syntax

Soul avoids uncontrolled growth.  
Syntax is frozen early and only expanded when it brings clear value.

Stability is an explicit design decision.

---

## Main features (v0.1)

- Indentation-based syntax
- Explicit typing
- Classic control flow (`if`, `while`, `for`)
- Compilation to C
- Custom runtime based on syscalls
- Efficient directory iteration
- High-performance filesystem operations
- Built-in progress feedback for long-running tasks
- Prepared for real parallelism

---

## Supported types (v0.1)

| Type    | Description |
|---------|-------------|
| int     | Integer |
| bool    | Boolean |
| str     | String (including filesystem paths) |
| diriter | Directory iterator |

> In Soul, filesystem paths are represented as `str`.

---

## Basic syntax

### Variables

```soul
int x = 5
str folder = arg("--folder")
```

### Conditionals

```soul
if x > 3:
    printStr("greater")
else:
    printStr("lower")
```

### Loops

#### while

```soul
int i = 0
while i < 5:
    print(i)
    i = i + 1
```

#### for (structural)

```soul
for str f in files:
    printStr(f)
```

---

## Standard library (excerpt)

### Filesystem

| Function | Description |
|---------|-------------|
| listdir(path) | Open directory iterator |
| dir_next(it) | Return next entry |
| dir_close(it) | Close iterator |
| copy(src, dst) | Copy file |
| copyFolder(src, dst) | Recursive directory copy |
| remove(path) | Remove file |
| removeTree(path) | Remove full directory tree |
| mkdir(path) | Create directory |

### Strings and utilities

| Function | Description |
|---------|-------------|
| print(x) | Print integer |
| printStr(s) | Print string |
| concat(a, b) | Concatenate strings |
| startswith(a, b) | Prefix check |
| endswith(a, b) | Suffix check |
| length(s) | Length |

### CLI

| Function | Description |
|---------|-------------|
| arg(name) | Get CLI argument |
| arg(name, def) | Argument with default value |

---

## Tooling

- `soul run` — execute Soul script
- `soul build` — compile to binary
- Custom runtime with no external dependencies
- Real-world examples in `/library`

---

## Project status

- Stable lexer
- Stable parser
- Functional analyzer
- Stable C compilation
- Custom runtime
- Syntax frozen (v0.1)
- Real-world scripts used for large copy and delete operations

Soul is already used to **copy and delete tens of gigabytes** with progress feedback.

---

## Mid-term vision

- Real parallelism
- Execution planning (`soul plan`)
- Binary export
- Extended standard library
- Compilation and distribution platform

Soul is a **systems tool**, not a general-purpose language.
>>>>>>> 0e82548 (Update README: English documentation for Soul v0.1)
