<?php
class DB {
    private $pdo;
    public function __construct() {
        $host='localhost';
        $user='root';
        $pass='123';
        $db='DB';
        $charset='utf8mb4';
        $dsn= "mysql:host=$host;dbname=$db;charset=$charset";
        $this->pdo=new PDO($dsn,$user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    public function getBook($id) {
        $sql="SELECT * from books where id=:id";
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute([':id'=>$id]);    
        $result=$stmt->fetch();
        return $result;  
    }
    public function updateStatus($id,$old_status,$new_status) {
        $sql="UPDATE books SET status= :new_status WHERE id= :id AND status= :old_status";
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute([':id'=>$id,':new_status'=>$new_status,':old_status'=>$old_status]);
        return $stmt->rowCount();
    }

}
?>