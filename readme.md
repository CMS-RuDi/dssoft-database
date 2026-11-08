# DS Soft DB

##### [Включен в CMS RuDi](https://github.com/CMS-RuDi/CMS-RuDi)

Класс для работы с базами данных MySQL через расширение mysqli.

Инкапсулирует подключение к БД, построение и выполнение запросов
SELECT/INSERT/UPDATE/DELETE, систему фильтров, JOIN, группировку, сортировку,
постраничный вывод, работу с таблицами и индексами, транзакции, а также сбор
отладочной информации о запросах.

## Возможности

* подключение к MySQL и автоматическое переподключение при потере соединения;
* построение SELECT с фильтрами, JOIN, GROUP BY, ORDER BY и LIMIT;
* выборка одной записи, значения одного поля и количества записей;
* INSERT, UPDATE, DELETE, INSERT ... ON DUPLICATE KEY UPDATE, инкремент и декремент полей;
* работа с таблицами и индексами: создание, удаление, проверка существования;
* транзакции с выбором уровня изоляции и автоматическим COMMIT/ROLLBACK;
* кеширование списков полей таблиц;
* журнал выполненных запросов и времени их выполнения.

## Требования

* PHP 5.6 или выше;
* расширение mysqli;
* расширение mbstring (используется в поиске filterRelated()).

## Подключение

Классы находятся в пространстве имён `DSSoft` в каталоге `src/DSSoft`
(PSR-4: `DSSoft\` → `src/DSSoft/`). Для подключения достаточно любого
автозагрузчика классов, например `dssoft/autoload`.

## Быстрый старт

```php
use DSSoft\DB;

$db = new DB('localhost', 'user', 'password', 'database', 'MySQL', array(
    'table_prefix' => 'ds_',
    'db_charset'   => 'utf8mb4',
));

// Выборка с фильтрами, сортировкой и постраничным выводом
$articles = $db->filterEqual('status', 'active')
    ->orderBy('date_created', 'desc')
    ->limitPage(1, 20)
    ->get('articles');

// Одна запись по идентификатору
$article = $db->getItemById('articles', 10);

// Количество записей под фильтром
$count = $db->filterLike('title', '%PHP%')->getCount('articles');

// Вставка, обновление и удаление
$id = $db->insert('articles', array('title' => 'Новая статья', 'status' => 'draft'));
$db->update('articles', $id, array('status' => 'active'));
$db->delete('articles', $id);
```

Произвольный запрос. Маркер `{#}` заменяется на префикс таблиц, параметры
подставляются через `vsprintf()`:

```php
$result = $db->query('SELECT * FROM {#}articles WHERE id = %d', array(10));

foreach ($result->fetchAll() as $row) {
    // ...
}
```

## Фильтры

Методы фильтров накапливают условия в WHERE и возвращают `$this`,
поэтому вызовы можно объединять в цепочку. Условия объединяются через AND,
для OR используется `filterOr()`.

```php
$db->filterEqual('status', 'active')
    ->filterGt('views', 100)
    ->filterNotEqual('author_id', 5)
    ->orderBy('views', 'desc')
    ->get('articles');
```

Группа условий открывается `filterStart()` и закрывается `filterEnd()`:

```php
$db->filterStart()
        ->filterEqual('status', 'active')
        ->filterOr()
        ->filterIn('category_id', array(1, 2, 3))
    ->filterEnd()
    ->filterGt('views', 100)
    ->get('articles');
```

Доступны методы: `filter()`, `filterEqual()`, `filterNotEqual()`, `filterGt()`,
`filterLt()`, `filterGtEqual()`, `filterLtEqual()`, `filterLike()`,
`filterNotLike()`, `filterBetween()`, `filterIn()`, `filterNotIn()`,
`filterIsNull()`, `filterNotNull()`, `filterDateYounger()`, `filterDateOlder()`,
`filterTimestampYounger()`, `filterTimestampGt()`, `filterTimestampLt()`,
`filterFunc()`, `filterRelated()` (полнотекстовый поиск).

## Постраничный вывод

```php
$db->limitPage($page, 20);      // записи для указанной страницы
$db->limitPagePlus($page, 20);  // на одну запись больше — для проверки следующей страницы
```

Количество записей на страницу по умолчанию задаётся свойством
`$db->default_perpage` и меняется методом `setPerPage()`.

## JOIN

```php
$db->joinLeft('categories', 'c', 'c.id = i.category_id')
    ->filterEqual('c.is_active', 1)
    ->get('articles');
```

Поддерживаются `join()`, `joinInner()`, `joinLeft()`, `joinRight()`,
`joinLeftOuter()`, `joinRightOuter()`, `joinExcludingLeft()`,
`joinExcludingRight()`.

## Транзакции

```php
$success = $db->processTransaction(function (DB $db) {
    $db->insert('articles', array('title' => 'Первая'));
    $db->insert('articles', array('title' => 'Вторая'));

    return true; // вернуть false — транзакция будет откачена
});
```

Также доступны методы `startTransaction()`, `endTransaction()`, `forUpdate()`,
`lockInShareMode()` и `setTransactionIsolationLevel()`.

## Работа с таблицами и индексами

```php
$db->createTable('articles', array(
    'id'           => array('type' => 'primary'),
    'title'        => array('type' => 'varchar', 'size' => 255, 'index' => true),
    'slug'         => array('type' => 'varchar', 'size' => 255, 'unique' => true),
    'is_published' => array('type' => 'bool', 'default' => 0),
    'content'      => array('type' => 'text'),
    'date_created' => array('type' => 'timestamp', 'default_current' => true),
), 'InnoDB');

$db->isTableExists('articles');
$db->isFieldExists('articles', 'title');
$db->addTableField('articles', 'hits', 'INT UNSIGNED NOT NULL DEFAULT 0');
$db->addIndex('articles', array('title', 'status'), 'title_status');
$db->dropIndex('articles', 'title_status');
$db->dropTableField('articles', 'hits');
$db->dropTable('articles');
```

## Опции подключения

| Опция             | Тип           | По умолчанию | Назначение                                                       |
|-------------------|---------------|--------------|------------------------------------------------------------------|
| `table_prefix`    | string        | `''`         | Префикс таблиц, подставляемый вместо маркера `{#}`               |
| `db_charset`      | string        | —            | Кодировка соединения; для `createTable()` по умолчанию `utf8`    |
| `db_port`         | integer\|null | `null`       | Порт MySQL                                                        |
| `db_socket`       | string\|null  | `null`       | Unix-сокет                                                        |
| `clear_sql_mode`  | boolean       | `false`      | Выполнить `SET sql_mode=''` при подключении                       |

## Синглтон

```php
use DSSoft\DBSingeltone;

$db = DBSingeltone::getInstance('localhost', 'user', 'password', 'database');

$articles = $db->filterEqual('status', 'active')->get('articles');
```

## Состав пакета

| Класс                              | Назначение                                              |
|------------------------------------|---------------------------------------------------------|
| `DSSoft\DB`                        | Основной класс: подключение, запросы, фильтры, CRUD     |
| `DSSoft\DB\Driver\DBInterface`     | Интерфейс драйвера базы данных                           |
| `DSSoft\DB\Driver\MySQL`           | Драйвер MySQL на mysqli                                  |
| `DSSoft\DB\Result\ResultInterface` | Интерфейс результата запроса                             |
| `DSSoft\DB\Result\MySQL_Result`    | Обёртка над результатом mysqli                           |
| `DSSoft\DB\Debug`                  | Журнал запросов и времени выполнения                      |
| `DSSoft\DBSingeltone`              | Синглтон для доступа к объекту DB                        |

## Документация

Подробные описания классов, методов и свойств находятся в PHPDoc-блоках
исходного кода. История изменений — в файле [version_log.md](version_log.md).
