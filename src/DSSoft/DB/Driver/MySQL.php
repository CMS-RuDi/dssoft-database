<?php

namespace DSSoft\DB\Driver;

use DSSoft\DB\Result\MySQL_Result;

class MySQL implements DBInterface
{

    protected $mysqli;
    protected $db_con_time;
    protected $host;
    protected $user;
    protected $password;
    protected $dbase;
    protected $options;

    /**
     * Время, через которое при PHP_SAPI == 'cli' нужно сделать реконнект
     * для случаев, когда mysql.connect_timeout по дефолту (60 с) и переопределить это поведение нельзя
     * @var integer
     */
    private $reconnect_time = 60;

    public function __construct()
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $this->mysqli = new \mysqli();
    }

    protected function _connect()
    {
        if ( !$this->status() ) {
            @ $this->mysqli->connect($this->host, $this->user, $this->password, $this->dbase, !empty($this->options['port']) ? $this->options['port'] : null, !empty($this->options['socket']) ? $this->options['socket'] : null);

            if ( !$this->mysqli->connect_error ) {
                $this->setParams();
            }
            else {
                \DSSoft\DB::error('MySqli Connection Error', $this->mysqli->connect_error);
                return false;
            }

            $this->db_con_time = time();
        }

        return true;
    }

    public function connect($host, $user, $password, $dbase, $options = [])
    {
        if ( $host != $this->host || $user != $this->user || $password != $this->password || $dbase != $this->dbase ) {
            $this->close();

            $this->host     = $host;
            $this->user     = $user;
            $this->password = $password;
            $this->dbase    = $dbase;
            $this->options  = $options;
        }

        return $this->_connect();
    }

    public function reconnect()
    {
        $this->close();
        $this->_connect();
    }

    public function status()
    {
        return @ $this->mysqli->ping();
    }

    public function close()
    {
        if ( !$this->ConError() && $this->status() ) {
            $this->mysqli->close();
        }
    }

    public function lastId()
    {
        return $this->mysqli->insert_id;
    }

    public function query($sql)
    {
        if ( PHP_SAPI == 'cli' && (time() - $this->db_con_time) >= $this->reconnect_time ) {
            $this->reconnect();
        }

        $result = $this->mysqli->query($sql);

        if ( !$this->mysqli->errno ) {
            if ( $result instanceof \mysqli_result ) {
                return new MySQL_Result($result);
            }

            return $result;
        }
    }

    public function affectedRows()
    {
        return $this->mysqli->affected_rows;
    }

    public function escape($string)
    {
        return $this->mysqli->real_escape_string($string);
    }

    protected function setParams()
    {
        if ( !empty($this->options['charset']) ) {
            $this->mysqli->set_charset($this->options['charset']);
        }

        if ( !empty($this->options['clear_sql_mode']) ) {
            $this->mysqli->query("SET sql_mode=''");
        }

        $this->mysqli->query("SET `time_zone` = '" . date('P') . "'");

        if ( defined('LC_LANGUAGE_TERRITORY') ) {
            $this->mysqli->query("SET lc_messages = '" . LC_LANGUAGE_TERRITORY . "'");
        }
    }

    public function Error()
    {
        if ( $this->mysqli->errno ) {
            return '#' . $this->mysqli->errno . ': ' . $this->mysqli->error;
        }
    }

    public function ConError()
    {
        return $this->mysqli->connect_error;
    }

    //========================================================================//

    public function autocommitOn()
    {
        $this->mysqli->autocommit(true);
        return $this;
    }

    public function autocommitOff()
    {
        $this->mysqli->autocommit(false);
        return $this;
    }

    public function rollback()
    {
        $this->mysqli->rollback();
        return $this;
    }

    public function commit()
    {
        $this->mysqli->commit();
        return $this;
    }

    public function beginTransaction()
    {
        $this->mysqli->begin_transaction();
        return $this;
    }

    //========================================================================//
}
