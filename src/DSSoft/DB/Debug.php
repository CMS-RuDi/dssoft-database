<?php

namespace DSSoft\DB;

class Debug {

    private $_timer = [];
    private $_log = [];

    public function startTimer($name) {
        $this->_timer[$name] = microtime(true);
    }

    public function log($name, $data, $error = false) {
        // таймер мог быть не запущен (например, ошибка возникла до его запуска)
        $start_time = isset($this->_timer[$name]) ? $this->_timer[$name] : microtime(true);

        $this->_log[] = array(
            'start_time' => $start_time,
            'execution_time' => number_format(microtime(true) - $start_time, 5),
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
