<?php

namespace DSSoft\DB\Result;

interface ResultInterface
{

    public function __construct($result);

    /**
     * Возвращает все строки в виде ассоциативного или обычного массива
     * @param bool $assoc Если true то в виде ассоциативного массива
     */
    public function fetchAll($assoc = true);

    /**
     * Возвращает следующую строку из набора результатов в виде ассоциативного массива
     */
    public function fetchAssoc();

    /**
     * Возвращает следующую строку из набора результатов в виде обычного массива
     */
    public function fetchRow();

    /**
     * Возвращает количество строк
     */
    public function rowsCount();

    /**
     * Освобождаем память и выполняем другие необходимые операции при уничтожении объекта результатов
     */
    public function __destruct();
}
