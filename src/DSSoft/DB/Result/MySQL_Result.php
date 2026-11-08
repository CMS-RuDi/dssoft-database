<?php

namespace DSSoft\DB\Result;

class MySQL_Result implements ResultInterface {
    private $_result;

    public function __construct($result) {
        $this->_result = $result;
    }

    public function fetchAll($assoc = true) {
        return $this->_result->fetch_all($assoc === true ? MYSQLI_ASSOC : MYSQLI_NUM);
    }

    public function fetchAssoc() {
        return $this->_result->fetch_assoc();
    }

    public function fetchRow() {
        return $this->_result->fetch_row();
    }

    public function rowsCount() {
        return $this->_result->num_rows;
    }

    public function __destruct() {
        if ($this->_result instanceof \mysqli_result) {
            $this->_result->free();
        }
    }

    public function getOriginalResult() {
        return $this->_result;
    }
}