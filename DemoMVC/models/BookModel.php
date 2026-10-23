<?php 
class DB
{
    private $pdo;
    public function __construct() {
        $host='127.0.0.1';
        $db='php_test';
        $user='phpuser';
        $pass='123456';
        $charset='utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset"; 
        $pdo=new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
       
    }
    public function getBook($id) {
        $sql = 'SELECT * FROM books WHERE id=:id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        $book = $stmt->fetch();
        return $book;
    }
    public function updateBook($id,$current_status,$new_status) {
        $sql = 'UPDATE books SET status=:status WHERE id=:id and status=:current_status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $new_status, PDO::PARAM_STR);
        $stmt->bindValue(':current_status', $current_status, PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

}
?>