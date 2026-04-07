# FileStruct

Справка по подсистеме работы со структурой файлов:

- сбор структуры по корню (обход файлов),
- запись структуры в TSV,
- чтение структуры из TSV,
- поиск по структуре,
- валидация реальной файловой системы по наличию, `mtime` и `md5`.

## Термины

- **корень структуры**: директория, относительно которой формируются относительные пути `relativePath`.
- **relativePath**: путь к файлу относительно корня структуры, всегда с `/` в качестве разделителя.
- **depth**: уровень вложенности файла относительно корня структуры, где `0` означает файл в корне.

## Формат файла структуры (TSV)

Одна строка = один файл. Разделитель полей — табуляция (`\t`).

Поля:

1. `indexLineNo` — номер строки в индексе (начиная с 1).
2. `relativePath` — относительный путь, нормализованный в `/`.
3. `depth` — целое число (0 и больше).
4. `sizeBytes` — размер файла в байтах (`filesize()`).
5. `mtimeUnix` — Unix timestamp времени модификации в секундах (`filemtime()`).
6. `sha256` — SHA-256 хэш содержимого файла (`hash_file('sha256', ...)`), 64 hex символа.

Пример строки:

```
1	src/classes/console/TimedConsoleApplication.php	2	1234	1712480343	9f61408e3afb633e50cdf1b20de6f466
```

## Сбор и запись структуры (`FileStruct`)

Класс: `app\kb\classes\FileStruct` (`src/classes/FileStruct.php`).

### `collectStructure()`

Обходит **только файлы** внутри каталога (подкаталога корня структуры) и вызывает коллбек для каждого файла:

- абсолютный путь
- depth относительно корня структуры
- relativePath относительно корня структуры

Симлинки не используются для выхода за пределы корня: если `startDir` окажется вне корня структуры, будет исключение.

### include/exclude фильтры

`FileStruct` поддерживает фильтрацию файлов по маскам:

- `setInclude(?array $include): self` — включающие маски. Если задано, в структуру попадут только файлы, путь которых (`relativePath`) совпал хотя бы с одной маской.
- `setExclude(?array $exclude): self` — исключающие маски. Если задано, из структуры будут исключены файлы, совпавшие хотя бы с одной маской.

Если `include`/`exclude` не заданы (null) — фильтрация не применяется.

Маски сравниваются с `relativePath` (относительный путь от корня структуры, с `/`) в стиле glob (через `fnmatch`).

### `writeFile()`

Пишет TSV **потоково** по мере обхода файлов, не накапливая данные в памяти.

Важно: порядок строк соответствует порядку обхода файловой системы и может различаться на разных окружениях.

## Чтение, поиск и валидация (`FileStructIndex`)

Класс: `app\kb\classes\FileStructIndex` (`src/classes/FileStructIndex.php`).

### Загрузка

`FileStructIndex::fromStructureFile(string $rootDir, string $structureFile): self`

Читает TSV построчно и строит индекс `relativePath -> DTO`.

DTO записи: `app\kb\classes\dto\FileStructEntry` (`src/classes/dto/FileStructEntry.php`).

### Поиск

- `hasPath(string $relativePath): bool`
- `getByPath(string $relativePath): FileStructEntry`
- `findByPrefix(string $prefix, callable $onFound): void`

### Валидация

Валидация выполняется относительно `rootDir` (корня структуры), по правилам:

- файл должен существовать и быть читаемым (`is_file`, `is_readable`)
- `filemtime()` должен совпасть с `mtimeUnix` (секунды)
- `hash_file('sha256', ...)` должен совпасть с `sha256`

Любая ошибка чтения (`filemtime`, `md5_file`) трактуется как «файл удалён/недоступен» и приводит к исключению.

Методы:

- `validatePath(string $relativePath): void`
- `validateEntry(FileStructEntry $entry): void`

### Исключения

Исключения находятся в `src/classes/exceptions/`:

- `FileStructEntryNotFoundException` — запись отсутствует в структуре.
- `FileStructFileMissingException` — файл удалён/недоступен/ошибка чтения.
- `FileStructFileChangedException` — файл изменён (mtime/md5 не совпали).

## Пример использования

```php
use app\kb\classes\FileStruct;
use app\kb\classes\FileStructIndex;

$root = '/repo';
$structureFile = '/repo/structure.tsv';

(new FileStruct($root))->writeFile('.', $structureFile);

$index = FileStructIndex::fromStructureFile($root, $structureFile);
$index->validatePath('src/classes/console/TimedConsoleApplication.php');
```

## Консольная команда

Команда: `kb:filestruct:dump` (класс `app\\kb\\classes\\command\\DumpCommand`).

Аргументы:
- `dir` — корень структуры (директория).
- `out` — путь к TSV-файлу структуры.

Опции:
- `--include` — маски включения (можно указывать несколько раз).
- `--exclude` — маски исключения (можно указывать несколько раз).

Пример:

```bash
./bin/console kb:filestruct:dump /repo /repo/structure.tsv --include="src/*" --exclude="*.log"
```

Пример с несколькими масками:

```bash
./bin/console kb:filestruct:dump /repo /repo/structure.tsv \
  --include="src/*" --include="docs/*.md" \
  --exclude="vendor/*" --exclude="*.log" --exclude="temp/*"
```

### Проверка файлов по индексу

Команда: `kb:filestruct:check` (класс `app\\kb\\classes\\command\\CheckFileStructCommand`).

Аргументы:
- `dir` — корень структуры (директория).
- `index` — путь к TSV-файлу индекса.

Опции:
- `--mask` — маски (glob) для поиска по `relativePath` (можно указывать несколько раз).

Команда ищет записи в индексе по маскам, валидирует каждый найденный файл в реальной директории и выводит отчёт в TSV-виде: `STATUS<TAB>PATH<TAB>DETAILS`.

Пример:

```bash
./bin/console kb:filestruct:check /repo /repo/structure.tsv --mask="src/*" --mask="docs/*.md"
```

### Поиск дубликатов по md5

Команда: `kb:filestruct:dups` (класс `app\\kb\\classes\\command\\FindDuplicatesCommand`).

Аргументы:
- `dir` — корень структуры (директория).
- `index` — путь к TSV-файлу индекса.

Опции:
- `--include` — маски включения (можно указывать несколько раз).
- `--exclude` — маски исключения (можно указывать несколько раз).

Команда читает индекс, группирует файлы по `sha256` и выводит группы, где один и тот же хэш
встречается у нескольких файлов.

Пример:

```bash
./bin/console kb:filestruct:dups /repo /repo/structure.tsv
```

Пример с фильтрами:

```bash
./bin/console kb:filestruct:dups /repo /repo/structure.tsv --include="src/*" --include="docs/*.md" --exclude="*.log"
```

### Поиск файлов по размеру

Команда: `kb:filestruct:size` (класс `app\\kb\\classes\\command\\FindBySizeCommand`).

Аргументы:
- `dir` — корень структуры (директория).
- `index` — путь к TSV-файлу индекса.

Опции:
- `--from` — минимальный размер в байтах (включительно).
- `--to` — максимальный размер в байтах (включительно).
- `--include` — маски включения (можно указывать несколько раз).
- `--exclude` — маски исключения (можно указывать несколько раз).

Пример:

```bash
./bin/console kb:filestruct:size /repo /repo/structure.tsv --from=0 --to=1024 --include="src/*" --exclude="*.log"
```

### Сравнение двух индексов

Команда: `kb:filestruct:diff` (класс `app\\kb\\classes\\command\\DiffIndexCommand`).

Аргументы:
- `dir` — корень структуры (директория).
- `left` — путь к TSV индексу (старый).
- `right` — путь к TSV индексу (новый).

Опции:
- `--include` — маски включения (можно указывать несколько раз).
- `--exclude` — маски исключения (можно указывать несколько раз).

Команда выводит отличия по `relativePath` в TSV-виде: `STATUS<TAB>PATH<TAB>DETAILS`, где `STATUS` — `ADDED`, `REMOVED` или `MODIFIED`.

Пример:

```bash
./bin/console kb:filestruct:diff /repo /repo/index-old.tsv /repo/index-new.tsv --include="src/*" --exclude="vendor/*"
```

