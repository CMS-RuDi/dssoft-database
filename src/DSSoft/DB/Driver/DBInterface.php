<?php

namespace DSSoft\DB\Driver;

interface DBInterface
{

    /**
     * Открывает новое соединени с сервером БД
     * @param string $host Адрес сервера
     * @param string $user Пользователь
     * @param string $password Пароль
     * @param string $dbase База данных
     * @param array $options Опции
     */
    public function connect($host, $user, $password, $dbase, $options = []);

    /**
     * Выполняет запрос в базе
     * @param string $sql Строка запроса
     * @return boolean|DB\Result\ResultInterface
     */
    public function query($sql);

    /**
     * Возвращает значение, созданное для столбца AUTO_INCREMENT последним запросом
     * @return int|string
     */
    public function lastId();

    /**
     * Возвращает число строк, затронутых предыдущей операцией
     * @return int|string
     */
    public function affectedRows();

    /**
     * Закрывает текущее соединение и открывает новое
     * @see connect
     */
    public function reconnect();

    /**
     * Подготавливает строку перед запросом
     * @param string $string
     * @return string
     */
    public function escape($string);

    /**
     * Возвращает true если подключение работает и false если нет
     * @return bool
     */
    public function status();

    /**
     * Закрывает текущее соединение
     */
    public function close();

    /**
     * Возвращает последнюю ошибку
     * @return string
     */
    public function Error();

    /**
     * Возвращает текст ошибки при подключении или null если ошибки не было
     * @return string|null
     */
    public function ConError();
}
