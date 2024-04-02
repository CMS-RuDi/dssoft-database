<?php

namespace DSSoft;

class DB {

    /**
     * Типы JOIN
     */
    const LEFT_JOIN = 'LEFT JOIN';
    const RIGHT_JOIN = 'RIGHT JOIN';
    const INNER_JOIN = 'INNER JOIN';
    const STRAIGHT_JOIN = 'STRAIGHT_JOIN';
    const LEFT_OUTER_JOIN = 'LEFT OUTER JOIN';
    const RIGHT_OUTER_JOIN = 'RIGHT OUTER JOIN';

    /**
     * Уровни изоляций транзакций
     */
    const READ_UNCOMMITTED = 'READ UNCOMMITTED';
    const READ_COMMITTED = 'READ COMMITTED';
    const REPEATABLE_READ = 'REPEATABLE READ';
    const SERIALIZABLE = 'SERIALIZABLE';

    /**
     * Объект для работы с БД
     * @var DB\Driver\DBInterface
     */
    protected $db;
    protected $db_type;
    protected $db_host;
    protected $db_user;
    protected $db_password;
    protected $db_name;
    protected $options;

    /**
     * Объект класса для отладки работы с БД
     * @var DB\Debug
     */
    protected $_debug;
    protected $_cache = array('table_fields' => array());
    //========================================================================//
    public $table = '';
    public $select = array('i.*');
    public $group_by = '';
    public $order_by = '';
    public $where = '';
    public $where_separator = 'AND';
    public $index_action = '';
    public $limit = 1000;
    public $distinct = '';
    public $straight_join = '';
    public $join = '';
    public $read_type = '';
    public $perpage = 50;
    public $is_transaction_started = false;
    public $filter_on = false;
    public $keep_filters = false;
    public $query_quiet = false;

    public function __construct($host, $user, $password, $dbname, $type = 'MySQL', $options = array()) {
        $this->db_type = $type;
        $this->db_host = $host;
        $this->db_user = $user;
        $this->db_password = $password;
        $this->db_name = $dbname;
        $this->options = $options;
        $this->_debug = new DB\Debug();

        if (empty($this->options['table_prefix'])) {
            $this->options['table_prefix'] = '';
        }

        $this->connect();
    }

    protected function connect() {
        $class_name = '\\DSSoft\\DB\\Driver\\' . $this->db_type;

        if (!class_exists($class_name)) {
            return false;
        }

        $this->_debug->startTimer('CONNECT_DB');

        $this->db = new $class_name();

        $this->db->connect($this->db_host, $this->db_user, $this->db_password, $this->db_name, $this->options);

        if ($this->db->ConError()) {
            $this->_debug->error('CONNECT_DB', array('msg' => $this->db->ConError()));
            return false;
        } else {
            $this->_debug->log('CONNECT_DB', array('msg' => 'SUCCESS'));
            return true;
        }
    }

    public function reconnect($forced = false) {
        if ($forced === true || !$this->db->status()) {
            $this->db->reconnect();
        }
    }

    public function ConError() {
        return $this->db->ConError();
    }

    public function __destruct() {
        $this->db->close();
    }

    //========================================================================//

    /**
     * Возвращает все названия полей для таблицы
     * @param string $table
     * @return array
     */
    public function getTableFields($table) {
        if (isset($this->_cache['table_fields'][$table])) {
            return $this->_cache['table_fields'][$table];
        }

        $result = $this->query("SHOW COLUMNS FROM `{#}" . $table . "`");

        $fields = array();

        while ($data = $result->fetchAssoc()) {
            $fields[] = $data['Field'];
        }

        $this->_cache['table_fields'][$table] = $fields;

        return $fields;
    }

    /**
     * Возвращает названия полей и их типы для таблицы
     * @param string $table
     * @return array
     */
    public function getTableFieldsTypes($table) {
        $result = $this->query("SELECT DATA_TYPE, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '{#}" . $table . "'");

        $fields = [];

        while ($data = $result->fetchAssoc()) {
            $fields[$data['COLUMN_NAME']] = $data['DATA_TYPE'];
        }

        return $fields;
    }

    //========================================================================//

    /**
     * Выполняет запрос в базе
     *
     * @param string $sql Строка запроса
     * @param array|string $params Аргументы запроса, которые будут переданы в vsprintf
     * @param boolean $quiet В случае ошибки запроса отдавать false, а не "умирать"
     *
     * @return boolean|DB\Result\ResultInterface
     */
    public function query($sql, $params = false, $quiet = false) {
        $this->_debug->startTimer('DB_QUERY');

        $sql = $this->replacePrefix($sql);

        if ($params) {
            if (!is_array($params)) {
                $params = array($params);
            }

            foreach ($params as $index => $param) {
                if (!is_numeric($param)) {
                    $params[$index] = $this->db->escape($param);
                }
            }

            $sql = vsprintf($sql, $params);
        }

        $result = $this->db->query($sql);

        if ($result === false && $this->db->Error()) {
            if ($quiet || $this->query_quiet === true) {
                $this->_debug->error('DB_QUERY', array('msg' => $this->db->Error(), 'sql' => $sql));
                return false;
            }
        } else {
            $this->_debug->log('DB_QUERY', array('sql' => $sql));
            return $result;
        }

        exit($this->db->Error() . "\n\nSQL:\n\n" . $sql);
    }

    //========================================================================//

    public function distinctSelect() {
        $this->distinct = 'DISTINCT';

        return $this;
    }

    public function select($field, $as = false) {
        $this->select[] = $as ? $field . ' as `' . $as . '`' : $field;

        return $this;
    }

    public function selectOnly($field, $as = false) {
        $this->select = array();
        $this->select[] = $as ? $field . ' as `' . $as . '`' : $field;

        return $this;
    }

    //========================================================================//

    public function getField($table_name, $row_id, $field_name) {
        $this->filterEqual('id', $row_id);
        return $this->getFieldFiltered($table_name, $field_name);
    }

    public function getFieldFiltered($table_name, $field_name) {
        $this->select = array($this->prepareFieldName($field_name));

        $this->table = $table_name;

        $this->limit(1);

        $sql = $this->getSQL();

        $this->resetFilters();

        $result = $this->query($sql);

        if (!$result->rowsCount()) {
            return false;
        }

        return $result->fetchRow()[0];
    }

    //========================================================================//

    public function getItem($table_name, $item_callback = false) {
        $select = implode(', ', $this->select);

        $sql = "SELECT {$select}
                FROM {#}{$table_name} i
                {$this->index_action}";

        if ($this->join) {
            $sql .= $this->join;
        }

        if ($this->where) {
            $sql .= 'WHERE ' . $this->where . PHP_EOL;
        }

        if ($this->order_by) {
            $sql .= 'ORDER BY ' . $this->order_by . PHP_EOL;
        }

        $sql .= 'LIMIT 1';

        if ($this->read_type) {
            $sql .= PHP_EOL . $this->read_type;
        }

        $this->resetFilters();

        $result = $this->query($sql);

        if (!$result->rowsCount()) {
            return false;
        }

        $item = $result->fetchAssoc();

        if (is_callable($item_callback)) {
            $item = call_user_func_array($item_callback, array($item, $this));
        }

        return $item;
    }

    public function getItemById($table_name, $id, $item_callback = false) {
        $this->filterEqual('id', $id);
        return $this->getItem($table_name, $item_callback);
    }

    public function getItemByField($table_name, $field_name, $field_value, $item_callback = false) {
        $this->filterEqual($field_name, $field_value);
        return $this->getItem($table_name, $item_callback);
    }

    //========================================================================//

    public function getCount($table_name, $by_field = 'id', $reset = false) {
        $sql = "SELECT {$this->straight_join} COUNT({$this->distinct} i.{$by_field} )
                FROM {#}{$table_name} i
                {$this->index_action}";

        if ($this->join) {
            $sql .= $this->join;
        }

        if ($this->where) {
            $sql .= 'WHERE ' . $this->where . PHP_EOL;
        }

        if ($this->group_by) {
            $sql .= 'GROUP BY ' . $this->group_by . PHP_EOL;
        }

        if ($reset) {
            $this->resetFilters();
        }

        $result = $this->query($sql);

        if (!$result->rowsCount()) {
            $count = 0;
        } else {
            $count = (int) $result->fetchRow()[0];
        }

        return $count;
    }

    //========================================================================//

    /**
     * Возвращает записи из базы, применяя все наложенные ранее фильтры
     * @return array
     */
    public function get($table_name, $item_callback = false, $key_field = 'id') {
        $this->table = $table_name;

        $items = array();

        $sql = $this->getSQL();

        $this->resetFilters();

        $result = $this->query($sql);

        if (!$result->rowsCount()) {
            return false;
        }

        while ($item = $result->fetchAssoc()) {
            $key = ($key_field && isset($item[$key_field])) ? $item[$key_field] : false;

            if (is_callable($item_callback)) {
                $item = call_user_func_array($item_callback, array($item, $this));

                if ($item === false) {
                    continue;
                }
            }

            if ($key) {
                $items[$key] = $item;
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }

    //========================================================================//

    public function delete($table_name, $id, $by_field = 'id') {
        $this->filterEqual($by_field, $id);

        return $this->deleteFiltered($table_name);
    }

    public function deleteFiltered($table_name) {
        $where = $this->where;

        $this->resetFilters();

        $this->_delete($table_name, $where);
    }

    /**
     * Выполняет запрос DELETE
     * @param string $table_name Таблица
     * @param string $where Критерии запроса
     * @return boolean
     */
    public function _delete($table_name, $where) {
        return $this->query("DELETE FROM {#}" . $table_name . " WHERE " . str_replace('i.', '', $where));
    }

    //========================================================================//

    public function update($table_name, $id, $data, $skip_check_fields = false, $array_as = 'json') {
        $this->filterEqual('id', $id);

        return $this->updateFiltered($table_name, $data, $skip_check_fields, $array_as);
    }

    public function updateFiltered($table_name, $data, $skip_check_fields = false, $array_as = 'json') {
        $where = $this->where;

        $this->resetFilters();

        return $this->_update($table_name, $where, $data, $skip_check_fields, $array_as);
    }

    /**
     * Выполняет запрос UPDATE
     *
     * @param string $table Таблица
     * @param string $where Критерии запроса
     * @param array $data Массив[Название поля] = значение поля
     * @param boolean $skip_check_fields Не проверять наличие обновляемых полей
     * @param string $array_as Преобразовать массив в строку с помошью json, yaml, serialize
     *
     * @return boolean
     */
    public function _update($table, $where, $data, $skip_check_fields = false, $array_as = 'json') {
        if (empty($data)) {
            return false;
        }

        if (!$skip_check_fields) {
            $table_fields = $this->getTableFields($table);
        }

        $set = [];

        foreach ($data as $field => $value) {
            if (!$skip_check_fields && !in_array($field, $table_fields)) {
                continue;
            }

            $set[] = "`" . $field . "` = " . $this->prepareValue($field, $value, $array_as);
        }

        if (!$set) {
            return false;
        }

        return $this->query("UPDATE {#}" . $table . " i SET " . implode(', ', $set) . " WHERE " . $where);
    }

    //========================================================================//

    /**
     * Выполняет запрос INSERT
     *
     * @param string $table Таблица
     * @param array $data Массив[Название поля] = значение поля
     * @param boolean $skip_check_fields Не проверять наличие обновляемых полей
     * @param boolean $array_as Преобразовать массив в строку с помошью json, yaml, serialize
     * @param boolean $ignore Пропускать записи, если при вставке возникают ошибки (INSERT IGNORE)
     * @return boolean|int|string ID вставленной записи
     */
    public function insert($table, $data, $skip_check_fields = false, $array_as = 'json', $ignore = false) {
        if (empty($data) || !is_array($data)) {
            return false;
        }

        if (!$skip_check_fields) {
            $table_fields = $this->getTableFields($table);
        }

        $fields = $values = [];

        foreach ($data as $field => $value) {
            if (!$skip_check_fields && !in_array($field, $table_fields)) {
                continue;
            }

            $fields[] = "`" . $field . "`";
            $values[] = $this->prepareValue($field, $value, $array_as);
        }

        if (!$fields) {
            return false;
        }

        $sql = "INSERT " . ($ignore ? 'IGNORE ' : '') . "INTO {#}" . $table . " (" . implode(', ', $fields) . ")\nVALUES (" . implode(', ', $values) . ")";

        if ($this->query($sql)) {
            return $this->db->lastId();
        }

        return false;
    }

    /**
     * Выполняет запрос INSERT
     * при совпадении PRIMARY или UNIQUE ключа выполняет UPDATE вместо INSERT
     *
     * @param string $table Таблица
     * @param array $data Массив данных для вставки в таблицу
     * @param array $update_data Массив данных для обновления при совпадении ключей
     * @param boolean $skip_check_fields Не проверять наличие обновляемых полей
     * @param string $array_as Преобразовать массив в строку с помошью json, yaml, serialize
     * @return boolean|int|string
     */
    public function insertOrUpdate($table, $data, $update_data = false, $skip_check_fields = false, $array_as = 'json') {
        $fields = $values = $set = [];

        if (is_array($data)) {
            foreach ($data as $field => $value) {
                $value = $this->prepareValue($field, $value, $array_as);

                $fields[] = "`" . $field . "`";
                $values[] = $value;

                if ($update_data === false) {
                    $set[] = "`" . $field . "` = " . $value;
                }
            }

            $sql = "INSERT INTO {#}" . $table . " (" . implode(', ', $fields) . ")\nVALUES (" . implode(', ', $values) . ")";

            if (is_array($update_data)) {
                foreach ($update_data as $field => $value) {
                    $set[] = "`" . $field . "` = " . $this->prepareValue($field, $value, $array_as);
                }
            }

            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $set);

            if ($this->query($sql)) {
                return $this->db->lastId();
            }
        }

        return false;
    }

    //========================================================================//

    public function increment($table, $field, $step = 1) {
        $sign = $step > 0 ? '+' : '-';
        $step = abs($step);

        $sql = "UPDATE {#}" . $table . " i " . PHP_EOL . "SET i." . $field . " = i." . $field . " " . $sign . " " . $step . PHP_EOL;

        if ($this->where) {
            $sql .= 'WHERE ' . $this->where;
        }

        $this->resetFilters();

        return $this->query($sql, false, true);
    }

    public function decrement($table, $field, $step = 1) {
        return $this->increment($table, $field, $step * -1);
    }

    //========================================================================//

    public function replaceFieldString($table_name, $search, $replace, $field) {

        $search = $this->db->escape($search);
        $replace = $this->db->escape($replace);

        return $this->query("UPDATE `{#}{$table_name}` SET `{$field}` = REPLACE(`{$field}`, '{$search}', '$replace') WHERE `{$field}` LIKE '%{$search}%'");
    }

    //========================================================================//

    public function limit($from, $howmany = 0) {
        $this->limit = (int) $from;
        $howmany = (int) $howmany;

        if ($this->limit < 0) {
            $this->limit = 0;
        }

        if ($howmany) {
            if ($howmany <= 0) {
                $howmany = 15;
            }

            $this->limit .= ', ' . $howmany;
        }

        return $this;
    }

    public function limitPage($page, $perpage = 0) {
        $page = (int) $page;
        $perpage = (int) $perpage;

        if ($perpage <= 0) {
            $perpage = $this->perpage;
        }

        $this->limit(($page - 1) * $perpage, $perpage);

        return $this;
    }

    public function limitPagePlus($page, $perpage = 0) {
        $page = (int) $page;
        $perpage = (int) $perpage;

        if ($perpage <= 0) {
            $perpage = $this->perpage;
        }

        $this->limit(($page - 1) * $perpage, $perpage + 1);

        return $this;
    }

    public function setPerPage($perpage) {
        $this->perpage = (int) $perpage;

        return $this;
    }

    //========================================================================//

    public function resetFilters() {
        $this->select = array('i.*');
        $this->group_by = '';
        $this->order_by = '';
        $this->index_action = '';
        $this->limit = '';
        $this->read_type = '';
        $this->join = '';
        $this->distinct = '';
        $this->straight_join = '';

        if ($this->keep_filters) {
            return $this;
        }

        $this->filter_on = false;
        $this->where = '';

        return $this;
    }

    public function lockFilters() {
        $this->keep_filters = true;
        return $this;
    }

    public function unlockFilters() {
        $this->keep_filters = false;
        return $this;
    }

    public function filterStart() {
        if ($this->filter_on) {
            $this->where .= ' ' . $this->where_separator . ' (';
        } else {
            $this->where .= '(';
        }

        $this->filter_on = false;

        return $this;
    }

    public function filterEnd() {
        $this->where .= ' ) ';
        return $this;
    }

    public function filterAnd() {
        $this->where_separator = ' AND ';
        return $this;
    }

    public function filterOr() {
        $this->where_separator = ' OR ';
        return $this;
    }

    public function filter($condition) {
        if ($this->filter_on) {
            $this->where .= ' ' . $this->where_separator . ' (' . $condition . ')';
        } else {
            $this->where .= '(' . $condition . ')';
            $this->filter_on = true;
        }

        $this->where_separator = ' AND ';

        return $this;
    }

    public function filterEqual($field, $value, $binary = false) {
        if (is_null($value)) {
            return $this->filterIsNull($field);
        }

        return $this->filter(($binary ? ' BINARY ' : '') . $field . " = '" . $this->db->escape($value) . "'");
    }

    public function filterNotEqual($field, $value) {
        if (is_null($value)) {
            return $this->filterNotNull($field);
        }

        $value = $this->db->escape($value);

        return $this->filter($this->prepareFieldName($field) . " <> '" . $value . "'");
    }

    public function filterNotNull($field) {
        return $this->filter($this->prepareFieldName($field) . ' IS NOT NULL');
    }

    public function filterIsNull($field) {
        return $this->filterEqual($this->prepareFieldName($field), ' IS NULL');
    }

    public function filterFunc($field, $value, $sign = '=') {
        return $this->filter($this->prepareFieldName($field) . " " . $sign . " " . $value);
    }

    public function filterGt($field, $value) {
        return $this->filterFunc($field, "'" . $this->db->escape($value) . "'", '>');
    }

    public function filterLt($field, $value) {
        return $this->filterFunc($field, "'" . $this->db->escape($value) . "'", '<');
    }

    public function filterGtEqual($field, $value) {
        return $this->filterFunc($field, "'" . $this->db->escape($value) . "'", '>=');
    }

    public function filterLtEqual($field, $value) {
        return $this->filterFunc($field, "'" . $this->db->escape($value) . "'", '<=');
    }

    public function filterLike($field, $value) {
        return $this->filter($this->prepareFieldName($field) . " LIKE '" . $this->db->escape($value) . "'");
    }

    public function filterNotLike($field, $value) {
        return $this->filter($this->prepareFieldName($field) . " NOT LIKE '" . $this->db->escape($value) . "'");
    }

    public function filterBetween($field, $start, $end) {
        return $this->filter($this->prepareFieldName($field) . " BETWEEN '" . $this->db->escape($start) . "' AND '" . $this->db->escape($value) . "'");
    }

    public function filterDateYounger($field, $value, $interval = 'DAY') {
        return $this->filter($this->prepareFieldName($field) . " >= DATE_SUB(NOW(), INTERVAL " . $this->db->escape($value) . " " . $this->db->escape($interval) . ")");
    }

    public function filterTimestampYounger($field, $value, $interval = 'DAY') {
        return $this->filter("TIMESTAMPDIFF(" . $this->db->escape($interval) . ", " . $this->prepareFieldName($field) . ", NOW()) <= " . (int) $value);
    }

    public function filterDateOlder($field, $value, $interval = 'DAY') {
        return $this->filter($this->prepareFieldName($field) . " < DATE_SUB(NOW(), INTERVAL " . $this->db->escape($value) . " " . $this->db->escape($interval) . ")");
    }

    public function filterTimestampGt($field, $value) {
        return $this->filter("UNIX_TIMESTAMP(" . $this->prepareFieldName($field) . ") > '" . $this->db->escape($value) . "'");
    }

    public function filterTimestampLt($field, $value) {
        return $this->filter("UNIX_TIMESTAMP(" . $this->prepareFieldName($field) . ") < '" . $this->db->escape($value) . "'");
    }

    public function filterIn($field, $value, $not = false) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = "'" . $this->db->escape($v) . "'";
            }

            $value = implode(',', $value);
        } else {
            $value = "'" . $this->db->escape($value) . "'";
        }
        return $this->filter($this->prepareFieldName($field) . ($not === true ? " NOT" : "") . " IN (" . $value . ")");
    }

    public function filterNotIn($field, $value) {
        return $this->filterIn($field, $value, true);
    }

    public function filterRelated($field, $value) {
        if (mb_strlen($value) <= 3) {
            return $this->filterLike($field, $this->db->escape($value) . '%');
        }

        $query = array();

        $words = explode(' ', str_replace(array('"', '\'', '+', '*', '-', '%', ')', '(', '.', ',', '!', '?'), ' ', $value));

        foreach ($words as $word) {
            $word = mb_strtolower(trim($word));

            if (mb_strlen($word) < 3 || is_numeric($word)) {
                continue;
            }

            if (mb_strlen($word) == 3) {
                $query[] = $this->db->escape($word);
                continue;
            }

            if (mb_strlen($word) >= 12) {
                $word = mb_substr($word, 0, mb_strlen($word) - 3) . '*';
            } else if (mb_strlen($word) >= 10) {
                $word = mb_substr($word, 0, mb_strlen($word) - 2) . '*';
            } else if (mb_strlen($word) >= 6) {
                $word = mb_substr($word, 0, mb_strlen($word) - 1) . '*';
            }

            $query[] = $this->db->escape($word);
        }

        if (empty($query)) {
            $ft_query = '\"' . $this->db->escape($value) . '\"';
        } else {
            usort($query, function ($a, $b) {
                return mb_strlen($b) - mb_strlen($a);
            });

            $query = array_slice($query, 0, 5);

            $ft_query = '>\"' . $this->db->escape($value) . '\" <(';
            $ft_query .= implode(' ', $query) . ')';
        }

        $search_param = "MATCH(" . $this->prepareFieldName($field) . ") AGAINST ('{$ft_query}' IN BOOLEAN MODE)";

        $this->select($search_param, 'fsort');

        $this->order_by = 'fsort desc';

        return $this->filter($search_param);
    }

    //========================================================================//

    public function setStraightJoin() {
        $this->straight_join = self::STRAIGHT_JOIN;
        return $this;
    }

    public function _join($join, $table_name, $as, $on) {
        $this->join .= $join . ' {#}' . $table_name . ' as ' . $as . ' ON ' . $on . PHP_EOL;

        return $this;
    }

    public function join($table_name, $as, $on) {
        return $this->joinInner($table_name, $as, $on);
    }

    public function joinInner($table_name, $as, $on) {
        return _join(self::INNER_JOIN, $table_name, $as, $on);
    }

    public function joinLeft($table_name, $as, $on) {
        return _join(self::LEFT_JOIN, $table_name, $as, $on);
    }

    public function joinExcludingLeft($table_name, $as, $right_key, $left_key, $join_where = '') {
        $this->filter($right_key . ' IS NULL');
        return _join(self::LEFT_JOIN, $table_name, $as, $left_key . '=' . $right_key . ($join_where ? ' AND ' . $join_where : ''));
    }

    public function joinRight($table_name, $as, $on) {
        return _join(self::RIGHT_JOIN, $table_name, $as, $on);
    }

    public function joinExcludingRight($table_name, $as, $right_key, $left_key, $join_where = '') {
        $this->filter($left_key . ' IS NULL');
        return _join(self::RIGHT_JOIN, $table_name, $as, $left_key . '=' . $right_key . ($join_where ? ' AND ' . $join_where : ''));
    }

    public function joinLeftOuter($table_name, $as, $on) {
        return _join(self::LEFT_OUTER_JOIN, $table_name, $as, $on);
    }

    public function joinRightOuter($table_name, $as, $on) {
        return _join(self::RIGHT_OUTER_JOIN, $table_name, $as, $on);
    }

    //========================================================================//

    public function groupBy($field) {
        $this->group_by = $this->prepareFieldName($field);

        return $this;
    }

    /**
     * Управляет индексами в запросе
     * @param string $index_name Название индекса в БД
     * @param string $action FORCE | IGNORE | USE
     * @param int $for 1 - FOR JOIN, 2 - FOR ORDER BY, 3 - FOR GROUP BY
     * @return $this
     */
    protected function indexHint($index_name, $action, $for = '') {
        switch ($for) {
            case 1:
                $for_action = 'FOR JOIN';
                break;
            case 2:
                $for_action = 'FOR ORDER BY';
                break;
            case 3:
                $for_action = 'FOR GROUP BY';
                break;
            default:
                $for_action = '';
                break;
        }

        $this->index_action .= "{$action} INDEX {$for_action} ({$index_name})\n";

        return $this;
    }

    public function forceIndex($index_name, $for = '') {
        return $this->indexHint($index_name, 'FORCE', $for);
    }

    public function ignoreIndex($index_name, $for = '') {
        return $this->indexHint($index_name, 'IGNORE', $for);
    }

    public function useIndex($index_name, $for = '') {
        return $this->indexHint($index_name, 'USE', $for);
    }

    public function orderBy($field, $direction = '', $is_force_index_by_field = false) {
        if (strpos($field, '(') === false) {
            $this->order_by = $this->prepareFieldName($field) . ' ' . (strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }

        return $this;
    }

    public function setReadType($type) {
        $this->read_type = $type;

        return $this;
    }

    //========================================================================//
    //============================== Транзакции ==================================//

    public function isAutocommitOn() {
        $result = $this->query('SELECT @@autocommit');

        if ($result->rowsCount()) {
            $row = $result->fetchRow();

            return isset($row[0]) && $row[0] == 1;
        }

        return false;
    }

    public function processTransaction($payload_callback, $after_autocommit_on = false) {
        // нам не нужно, чтобы внутри транзакции при ошибке запроса
        // всё умирало
        $this->query_quiet = true;

        // флаг результата выполнения
        $success = true;

        // мы внутри транзакции?
        $is_autocommit_on = $this->isAutocommitOn();

        // выключаем автокоммит, чтобы все запросы были в транзакции
        // если автокоммит выключен, то мы уже в транзакции
        if ($is_autocommit_on) {
            $this->db->autocommitOff();
        }

        try {
            $success = call_user_func_array($payload_callback, array($this));
        } catch (Exception $e) {
            self::error('Call transaction fn', $e->getMessage());
            $success = false;
        }

        $this->query_quiet = null;

        if ($is_autocommit_on || $after_autocommit_on) {
            $this->endTransaction($success);
        }

        return $success;
    }

    public function startTransaction() {
        $this->is_transaction_started = true;

        $this->db->autocommitOff();

        return $this;
    }

    public function endTransaction($success) {
        if ($success) {
            $this->db->commit();
        } else {
            $this->db->rollback();
        }

        $this->db->autocommitOn();

        $this->is_transaction_started = false;

        return $this;
    }

    public function forUpdate() {
        return $this->setReadType('FOR UPDATE');
    }

    public function lockInShareMode() {
        return $this->setReadType('LOCK IN SHARE MODE');
    }

    public function setTransactionIsolationLevel($level) {
        $this->query("SET TRANSACTION ISOLATION LEVEL " . $level);
        return $this;
    }

    //========================================================================//

    public function getSQL() {
        $select = implode(', ', $this->select);

        $sql = "SELECT {$this->distinct} {$this->straight_join} {$select}
                FROM {#}{$this->table} i
                {$this->index_action}";

        if ($this->join) {
            $sql .= $this->join;
        }

        if ($this->where) {
            $sql .= 'WHERE ' . $this->where . PHP_EOL;
        }

        if ($this->group_by) {
            $sql .= 'GROUP BY ' . $this->group_by . PHP_EOL;
        }

        if ($this->order_by) {
            $sql .= 'ORDER BY ' . $this->order_by . PHP_EOL;
        }

        if ($this->limit) {
            $sql .= 'LIMIT ' . $this->limit . PHP_EOL;
        }

        if ($this->read_type) {
            $sql .= $this->read_type . PHP_EOL;
        }

        return $sql;
    }

    //========================================================================//

    protected function prepareFieldName($field) {
        if (strpos($field, '.') === false) {
            $field = 'i.' . $field;
        }

        return $field;
    }

    /**
     * Формирует префиксы таблиц в SQL запросе
     * @param string $sql
     * @return string
     */
    public function replacePrefix($sql) {
        return str_replace(['{#}'], [$this->options['table_prefix']], $sql);
    }

    /**
     * Подготавливает значение $value поля $field для вставки в запрос
     *
     * @param string $field
     * @param mixed $value
     * @param string $array_as Преобразовать массив в строку с помошью json, yaml, serialize
     *
     * @return string
     */
    public function prepareValue($field, $value, $array_as = 'json') {
        $is_date_field = strpos($field, 'date_') === 0;

        if (is_array($value)) {
            switch ($array_as) {
                case 'yaml':
                    if (function_exists('yaml_emit')) {
                        $value = $this->db->escape(yaml_emit($array, YAML_UTF8_ENCODING));
                    } else if (class_exists('\Symfony\Component\Yaml\Yaml')) {
                        $value = $this->db->escape(\Symfony\Component\Yaml\Yaml::dump($value));
                    }
                    break;
                case 'serialize':
                    $value = $this->db->escape(serialize($value));
                    break;
                default:
                    $value = $this->db->escape(json_encode($value));
                    break;
            }

            $value = "'" . $value . "'";
        } else if ($is_date_field && ($value === false)) {
            $value = 'NULL';
        } else if ($is_date_field && ($value == '' || is_null($value))) {
            $value = 'CURRENT_TIMESTAMP';
        } else if (is_bool($value)) {
            $value = (int) $value;
        } else if ($value === '' || is_null($value)) {
            $value = 'NULL';
        } else if (is_callable($value) && ($value instanceof Closure)) {
            $value = $value($this);
        } else {
            $value = $this->escape(trim($value));
            $value = "'" . $value . "'";
        }

        return $value;
    }

    //========================================================================//

    public function dropTable($table_name) {
        return $this->query("DROP TABLE IF EXISTS {#}" . $table_name);
    }

    public function dropTableField($table_name, $field_name) {
        return $this->query("ALTER TABLE `{#}" . $table_name . "` DROP `" . $field_name . "`");
    }

    public function addTableField($table_name, $field_name, $sql) {

        if ($this->isFieldExists($table_name, $field_name)) {
            return false;
        }

        return $this->query("ALTER TABLE `{#}" . $table_name . "` ADD `" . $field_name . "` " . $sql);
    }

    public function isTableExists($table_name) {

        $result = $this->query('show tables');

        $tables = [];

        while ($data = $this->fetchRow($result)) {
            $tables[] = $data[0];
        }

        $table_name = $this->replacePrefix('{#}' . $table_name);

        return in_array($table_name, $tables, true);
    }

    public function createTable($table_name, $structure, $engine = 'MYISAM') {
        $sql = "CREATE TABLE IF NOT EXISTS `{#}" . $table_name . "` (" . PHP_EOL;

        $fcount = 0;
        $ftotal = sizeof($structure);

        $indexes = $fulltext = $unique = $indexes_created = [];

        foreach ($structure as $name => $field) {
            $fcount++;
            $sep = ($fcount == $ftotal) ? PHP_EOL : "," . PHP_EOL;

            $default = (!isset($field['default']) ? 'NULL' : "NOT NULL DEFAULT '" . $field['default'] . "'");
            $unsigned = (!isset($field['unsigned']) ? '' : 'UNSIGNED');

            // обычный индекс
            if (isset($field['index'])) {
                if ($field['index'] === true) {
                    $indexes[$name] = array($name);
                } else if (is_string($field['index'])) {
                    $indexes[$field['index']][$field['composite_index']] = $name;
                } else if (is_array($field['index'])) {
                    foreach ($field['index'] as $k => $i_name) {
                        $indexes[$i_name][$field['composite_index'][$k]] = $name;
                    }
                }
            }

            // уникальный индекс
            if (isset($field['unique'])) {
                $unique[] = $name;
            }

            // полнотекстовый индекс
            if (isset($field['fulltext'])) {
                if ($field['fulltext'] === true) {
                    $fulltext[$name] = array($name);
                }
            }

            switch ($field['type']) {
                case 'primary':
                    $sql .= "\t`" . $name . "` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY" . $sep;
                    break;
                case 'bool':
                    $sql .= "\t`" . $name . "` tinyint(1) UNSIGNED " . $default . "" . $sep;
                    break;
                case 'timestamp':
                    $current = (isset($field['default_current']) && $field['default_current'] == true) ? "NOT NULL DEFAULT CURRENT_TIMESTAMP" : 'NULL';
                    $sql .= "\t`" . $name . "` TIMESTAMP " . $current . "" . $sep;
                    break;
                case 'tinyint':
                    $sql .= "\t`" . $name . "` TINYINT " . $unsigned . " " . $default . "" . $sep;
                    break;
                case 'int':
                    if (!isset($field['size'])) {
                        $field['size'] = 11;
                    }
                    $sql .= "\t`" . $name . "` INT( " . $field['size'] . " ) " . $unsigned . " " . $default . "" . $sep;
                    break;
                case 'varchar':
                    if (!isset($field['size'])) {
                        $field['size'] = 255;
                    }
                    $sql .= "\t`" . $name . "` VARCHAR( " . $field['size'] . " ) " . $default . "" . $sep;
                    break;
                case 'text':
                    $sql .= "\t`" . $name . "` TEXT NULL" . $sep;
                    break;
                case 'set':
                    $values = array_map(function ($item) {
                        return '"' . $item . '"';
                    }, $field['items']);
                    $values = implode(',', $values);
                    $sql .= "\t`" . $name . "` SET (" . $values . ") " . $default . "" . $sep;
                    break;
                default: break;
            }
        }

        $sql .= ") ENGINE=" . $engine . " DEFAULT CHARSET=" . $this->options['db_charset'];

        $this->query($sql);

        foreach ($indexes as $index_name => $fields) {
            if (in_array($index_name, $indexes_created)) {
                continue;
            }

            $this->addIndex($table_name, $fields, $index_name);

            $indexes_created[] = $index_name;
        }

        foreach ($unique as $field) {
            if (in_array($field, $indexes_created)) {
                continue;
            }

            $this->addIndex($table_name, $field, $field, 'UNIQUE');

            $indexes_created[] = $field;
        }

        foreach ($fulltext as $index_name => $fields) {
            if (in_array($index_name, $indexes_created)) {
                continue;
            }

            $this->addIndex($table_name, $fields, $index_name, 'FULLTEXT');

            $indexes_created[] = $index_name;
        }
    }

    public function isFieldUnique($table_name, $field_name, $value, $exclude_row_id = false) {

        $value = $this->escape(trim($value));

        $where = "(`{$field_name}` = '{$value}')";

        if ($exclude_row_id) {
            $where .= " AND (id <> '{$exclude_row_id}')";
        }

        return !(bool) $this->getRowsCount($table_name, $where, 1);
    }

    public function isFieldExists($table_name, $field) {

        $table_fields = $this->getTableFields($table_name);

        return in_array($field, $table_fields, true);
    }

    /**
     * Возвращает поля, участвующие в индексе или false, если индекса нет
     * если индекс составной, то поля будут упорядочены в массиве как в индексе
     * @param string $table Название таблицы без префикса
     * @param string $index_name Название индекса
     * @return array|boolean
     */
    public function getIndex($table, $index_name) {
        $result = $this->query("SHOW INDEX FROM  `{#}" . $table . "` WHERE `Key_name` =  '" . $index_name . "'");

        if ($result->rowsCount()) {
            $fields = array();

            while ($i = $result->fetchAssoc()) {
                $fields[] = $i['Column_name'];
            }
            return $fields;
        } else {
            return false;
        }
    }

    /**
     * Возвращает все индексы таблицы
     * @param string $table Название таблицы без префикса
     * @param string $index_type Тип индекса
     * @return boolean|array
     */
    public function getTableIndexes($table, $index_type = null) {
        $sql = "SHOW INDEX FROM  `{#}" . $table . "`";
        if ($index_type) {
            $sql .= " WHERE `Index_type` = '" . $index_type . "'";
        }

        $result = $this->query($sql);

        if ($result->rowsCount()) {
            $indexes = array();

            while ($i = $result->fetchAssoc()) {
                $indexes[$i['Key_name']][] = $i['Column_name'];
            }

            return $indexes;
        }

        return false;
    }

    /**
     * Проверяет, есть ли указанный индекс в таблице
     * @param string $table Название таблицы без префикса
     * @param string $index_name Название индекса
     * @return boolean
     */
    public function isIndexExists($table, $index_name) {
        return $this->getIndex($table, $index_name) !== false;
    }

    /**
     * Удаляет индекс из таблицы, если он там есть
     * @param string $table Название таблицы без префикса
     * @param string $index_name Название индекса
     * @return boolean
     */
    public function dropIndex($table, $index_name) {
        if ($this->isIndexExists($table, $index_name)) {
            $this->query("ALTER TABLE `{#}" . $table . "` DROP INDEX `" . $index_name . "`");
            return true;
        }

        return false;
    }

    /**
     * Добавляет индекс к таблице
     * @param string $table Название таблицы без префикса
     * @param array|string $fields Поле или поля, участвующие в индексе
     * @param string $index_name Название индекса. Если не передано, название будет по последнему элементу
     * @param string $index_type Тип индекса
     * @return boolean FALSE если индекс с таким названием уже есть
     */
    public function addIndex($table, $fields, $index_name = '', $index_type = 'INDEX') {

        if (is_string($fields)) {
            $fields = array($fields);
        }

        if (!$index_name) {
            $index_name = end($fields);
        }

        if ($this->isIndexExists($table, $index_name)) {
            return false;
        }

        ksort($fields);

        $fields_str = '`' . implode('`, `', $fields) . '`';

        $this->query("ALTER TABLE `{#}" . $table . "` ADD " . $index_type . " `" . $index_name . "` (" . $fields_str . ")");

        return true;
    }

    //========================================================================//

    public function __call($name, $arguments) {
        return call_user_func_array(array($this->db, $name), $arguments);
    }

    public function getDebug() {
        return $this->_debug;
    }
}
