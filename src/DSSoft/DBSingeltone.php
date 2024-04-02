<?php

namespace DSSoft;

class DBSingeltone {

    protected static $_instance;
    protected static $_db;

    protected function __construct($host, $user, $password, $dbase, $type, $options) {
        self::$_db = new DB($host, $user, $password, $dbase, $type, $options);
    }

    public static function getInstance(...$arguments) {
        if (self::$_instance === null && count($arguments) > 3) {
            self::$_instance = new self($arguments[0], $arguments[1], $arguments[2], $arguments[3], isset($arguments[4]) ? $arguments[4] : 'MySQL', isset($arguments[5]) ? $arguments[5] : []);
        }

        return self::$_instance;
    }

    public function __call($name, $arguments) {
        return call_user_func_array(array(self::$_db, $name), $arguments);
    }

    protected function __clone() {

    }

    protected function __sleep() {

    }

    protected function __wakeup() {

    }
}
