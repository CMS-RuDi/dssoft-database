<?php

namespace DSSoft\DB;

class Debug {

    private $_timer = [];
    private $_log = [];

    public function startTimer($name) {
        $this->_timer[$name] = microtime(true);
    }

    public function log($name, $data, $error = false) {
        $this->_log[] = array(
            'start_time' => $this->_timer[$name],
            'execution_time' => number_format(microtime(true) - $this->_timer[$name], 5),
            'name' => $name,
            'data' => $data,
            'error' => $error
        );
    }

    public function error($name, $data) {
        $this->log($name, $data, true);
    }

    public function getLog() {
        return $this->_log;
    }

    public function getErrorLog() {
        $error = [];

        foreach ($this->_log as $log) {
            if ($log['error'] == true) {
                $error[] = $log;
            }
        }

        return $error;
    }
}
