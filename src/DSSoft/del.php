<?php

class cmsDatabase {

    /**
     * deprecated, use cmsDebugging
     */
    public $query_count = 0;
    public $query_list = array();

    /**
     * Массив кешированного списка полей для запрашиваемых таблиц
     * @var array
     */
    private $table_fields = array();

    /**
     *
     * @var boolean|null
     */
    public $query_quiet = null;

    /**
     * Настройки базы данных
     * @var array
     */
    private $options = [
        'db_charset' => 'utf8'
    ];

//============================================================================//

    public function __destruct() {
        if ($this->ready()) {

            // откатываемся, если была транзакция и ошибка в ней
            if (!$this->isAutocommitOn() && $this->mysqli->errno) {
                $this->rollback();
            }

            $this->mysqli->close();
        }
    }

    public function ready() {
        return $this->connect_error === false;
    }

    public function getStat() {

        if (isset($this->mysqli->stat)) {
            return $this->mysqli->stat;
        }

        return '';
    }

    public function importDump($file, $delimiter = ';') {

        clearstatcache();

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        if (!is_readable($file)) {
            return false;
        }

        @set_time_limit(0);

        $file = fopen($file, 'r');

        $query = [];
        $success = false;

        while (feof($file) === false) {

            $query[] = fgets($file);

            if (preg_match('~' . preg_quote($delimiter, '~') . '\s*$~iS', end($query)) === 1) {

                $success = true;

                $query = trim(implode('', $query));

                $result = $this->query(str_replace(['InnoDB', 'CHARSET=utf8'], [$this->options['db_engine'], 'CHARSET=' . $this->options['db_charset']], $query));

                if ($result === false) {
                    return false;
                }
            }

            if (is_string($query) === true) {
                $query = array();
            }
        }

        fclose($file);

        return $success;
    }
}
