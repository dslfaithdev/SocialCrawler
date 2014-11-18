<?php

function dbConnect($timeout, $options = array()) {
    $db = new pdo(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "0");
    foreach($options as $option)
        $db->exec($option);
    return $db;
}

class ReconnectingPDO
{
    protected $dsn, $username, $password, $pdo, $driver_options;

    public function __construct($dsn, $username = "", $password = "", $driver_options = array())
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->driver_options = $driver_options;
    }

    public function __call($name, array $arguments)
    {
        try {
            $this->connection()->query("SHOW STATUS;")->execute();
        } catch(\PDOException $e) {
            if($e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away')) {
                //throw $e;
            }

            $this->reconnect();
        }

        return call_user_func_array(array($this->connection(), $name), $arguments);
    }

    protected function connection()
    {
        return $this->pdo instanceof \PDO ? $this->pdo : $this->connect();
    }

    public function connect()
    {
        $this->pdo = new PDO($this->dsn, $this->username, $this->password, (array) $this->driver_options);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $this->pdo;
    }

    public function reconnect()
    {
        $this->pdo = null;
        return $this->connect();
    }
}
?>
