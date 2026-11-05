<?php

namespace DSSoft;

class DBSingeltone {
    protected static $_instance;
    protected static $_db;

    protected function __construct($host, $user, $password, $dbase, $type, $options) {
        self::$_db = new DB($host, $user, $password, $dbase, $type, $options);
    }

    public static function getInstance(...$arguments) {
        if (self::$_instance === null) {
            if (count($arguments) < 4) {
                throw new \InvalidArgumentException('DBSingeltone::getInstance() requires 4 arguments: host, user, password, database');
            }

            self::$_instance = new self(
                $arguments[0],
                $arguments[1],
                $arguments[2],
                $arguments[3],
                isset($arguments[4]) ? $arguments[4] : 'MySQL',
                isset($arguments[5]) ? $arguments[5] : []
            );
        }

        return self::$_instance;
    }

    public function __call($name, $arguments) {
        if (!self::$_db instanceof DB) {
            return false;
        }

        return call_user_func_array(array(self::$_db, $name), $arguments);
    }

    protected function __clone() {

    }

    /**
     * Синглтон нельзя сериализовать: соединение с БД не переносится между процессами
     */
    public function __sleep() {
        throw new \LogicException('Serialization of ' . __CLASS__ . ' is not allowed');
    }

    public function __wakeup() {
        throw new \LogicException('Unserialization of ' . __CLASS__ . ' is not allowed');
    }
}