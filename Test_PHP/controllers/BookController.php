<?php
require_once __DIR__.'/../models/BookModel.php';
class BookController {
    public function index() {
        $id=null;
        $success="";
        $error= "";
        $book=null;
        try {
            $db=new DB();
            if($_SERVER['REQUEST_METHOD'] === 'POST') {
                $id=$_POST['id']??null;
                if($id===null) {
                    $error= 'Please fill ID';
                } elseif(!is_string($id)) {
                    $error= 'ID cannot be an array';
                } else {
                    $id=trim($id);
                    if($id==="") {
                        $error= "ID cannot be empty";
                    } elseif(!ctype_digit($id)) {
                        $error="ID must be an integer";
                    }
                    else {
                        $id=ltrim($id,'0');
                        $validateID=filter_var($id,FILTER_VALIDATE_INT,["options"=>["min_range"=>1]]);
                        if($validateID===false) {
                            $error= "ID must > 0";
                        } else {
                            $book=$db->getBook($validateID);
                            if(!$book) { 
                                $error= "Book not found";
                            }
                        }

                    }
                }
            }
            $action=$_POST["action"]??null;
            if ($action=== "update_status") { 
                if(!$book) {
                    if($error=== "") {
                        $error="Book not found to update.";
                    }
                }
                elseif($book["status"]==="available") {
                    $rowAffected=$db->updateStatus($book["id"],$book["status"],"reserved");
                    if ($rowAffected>0) {
                        $success= "Update status complete";
                        $book["status"]= "reserved";
                    } else {
                        $book=$db->getBook($book["id"]);
                        if(!$book) {
                            $error="Book not found, book may be delete";
                        } else {
                            $error="Status book has been changed.";
                        }
                    }
                } elseif (in_array($book["status"],['reserved','borrowed'])) {
                    $error="Cannot be update. Current status:" .$book["status"]; 
                } else {
                    $error="Unknown status to update";
                }
            }
        } catch(PDOException $e) {
            error_log($e->getMessage());
            $success= "";
            $error= "Database connection error";
            $book=null;
            $id=null;
        }
        require_once __DIR__.'/../views/book_search.php';
    }
}
?>