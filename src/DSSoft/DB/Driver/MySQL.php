<?php

namespace DSSoft\DB\Driver;

use DSSoft\DB\Result\MySQL_Result;

class MySQL implements DBInterface {
    protected $mysqli;
    protected $connected = false;
    protected $connect_error = null;
    protected $db_con_time = 0;
    protected $host;
    protected $user;
    protected $password;
    protected $dbase;
    protected $options = [];

    /**
     * Время, через которое при PHP_SAPI == 'cli' нужно сделать реконнект
     * для случаев, когда mysql.connect_timeout по дефолту (60 с) и переопределить это поведение нельзя
     * @var integer
     */
    private $reconnect_time = 60;

    public function __construct() {
        mysqli_report(MYSQLI_REPORT_OFF);

        $this->mysqli = new \mysqli();
    }

    protected function _connect() {
        if ($this->status()) {
            return true;
        }

        // новое соединение создаём на чистом объекте mysqli,
        // чтобы не зависеть от состояния предыдущего (не)удачного подключения
        $this->mysqli = new \mysqli();
        $this->connected = false;

        $port = !empty($this->options['db_port']) ? (int) $this->options['db_port'] : null;
        $socket = !empty($this->options['db_socket']) ? $this->options['db_socket'] : null;

        @ $this->mysqli->connect($this->host, $this->user, $this->password, $this->dbase, $port, $socket);

        if ($this->mysqli->connect_error) {
            // текст ошибки доступен через ConError(), логирует его DSSoft\DB::connect()
            $this->connect_error = $this->mysqli->connect_error;
            return false;
        }

        $this->connected = true;
        $this->connect_error = null;
        $this->db_con_time = time();

        $this->setParams();

        return true;
    }

    public function connect($host, $user, $password, $dbase = null, $options = []) {
        if ($host != $this->host || $user != $this->user || $password != $this->password || $dbase != $this->dbase || $options != $this->options) {
            $this->close();

            $this->host = $host;
            $this->user = $user;
            $this->password = $password;
            $this->dbase = $dbase;
            $this->options = $options;
        }

        return $this->_connect();
    }

    public function reconnect() {
        $this->close();
        return $this->_connect();
    }

    public function status() {
        // ping() нельзя вызывать на закрытом/неинициализированном объекте mysqli
        if (!$this->connected) {
            return false;
        }

        return @ $this->mysqli->ping();
    }

    public function close() {
        if (!$this->connected) {
            return;
        }

        @ $this->mysqli->close();

        $this->connected = false;
        $this->db_con_time = 0;
    }

    public function lastId() {
        return $this->connected ? $this->mysqli->insert_id : false;
    }

    public function query($sql) {
        if (!$this->connected) {
            $this->_connect();
        }

        if (PHP_SAPI == 'cli' && $this->db_con_time > 0 && (time() - $this->db_con_time) >= $this->reconnect_time) {
            $this->reconnect();
        }

        $result = @ $this->mysqli->query($sql);

        // 2006 — MySQL server has gone away, 2013 — lost connection during query
        if ($result === false && in_array($this->mysqli->errno, array(2006, 2013), true)) {
            $this->reconnect();

            $result = @ $this->mysqli->query($sql);
        }

        if ($this->mysqli->errno) {
            return false;
        }

        if ($result instanceof \mysqli_result) {
            return new MySQL_Result($result);
        }

        return $result;
    }

    public function affectedRows() {
        return $this->connected ? $this->mysqli->affected_rows : 0;
    }

    public function escape($string) {
        if (!$this->connected) {
            // экранирование невозможно без соединения (и без знания кодировки)
            $this->_connect();
        }

        $escaped = @ $this->mysqli->real_escape_string((string) $string);

        if ($escaped === false || $escaped === null) {
            return addslashes((string) $string);
        }

        return $escaped;
    }

    protected function setParams() {
        if ($this->options['db_charset']) {
            @ $this->mysqli->set_charset($this->options['db_charset']);
        }

        if (!empty($this->options['clear_sql_mode'])) {
            $this->mysqli->query("SET sql_mode=''");
        }

        $this->mysqli->query("SET `time_zone` = '" . date('P') . "'");

        if (defined('LC_LANGUAGE_TERRITORY')) {
            $this->mysqli->query("SET lc_messages = '" . LC_LANGUAGE_TERRITORY . "'");
        }
    }

    public function Error() {
        if (!$this->connected) {
            return $this->connect_error;
        }

        if ($this->mysqli->errno) {
            return '#' . $this->ErrorNo() . ': ' . $this->mysqli->error;
        }

        return null;
    }

    public function ErrorNo() {
        if (!$this->connected) {
            return 0;
        }

        return (int) $this->mysqli->errno;
    }

    public function ConError() {
        return $this->mysqli->connect_error;
    }

    //========================================================================//

    public function autocommitOn() {
        if ($this->connected) {
            $this->mysqli->autocommit(true);
        }

        return $this;
    }

    public function autocommitOff() {
        if ($this->connected) {
            $this->mysqli->autocommit(false);
        }

        return $this;
    }

    public function rollback() {
        if ($this->connected) {
            $this->mysqli->rollback();
        }

        return $this;
    }

    public function commit() {
        if ($this->connected) {
            $this->mysqli->commit();
        }

        return $this;
    }

    public function beginTransaction() {
        if ($this->connected) {
            $this->mysqli->begin_transaction();
        }

        return $this;
    }

    //========================================================================//
}