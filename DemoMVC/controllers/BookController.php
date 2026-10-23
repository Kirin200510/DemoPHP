<?php
require_once __DIR__.'/../models/BookModel.php';
class BookController {
    public function index() {
        $error="";
        $success="";
        $book=null;
        $id=null;
        try {
            $db=new DB();
            if ($_SERVER['REQUEST_METHOD']==='POST') {
                $id=$_POST['id']??null;
                if($id===null) {
                    $error='Bắt buộc nhập ID.';
                } elseif(!is_string($id)) {
                    $error='ID phải là giá trị đơn không phải dạng mảng';
                } else {
                    $id=trim($id);
                    if($id==='') {
                        $error='Bắt buộc nhập ID.';
                    } elseif(!ctype_digit($id)) {
                        $error='ID phải là một số nguyên dương.';
                    } else {
                        $id=(int)$id;
                        $validateId=filter_var(
                            $id,
                            FILTER_VALIDATE_INT,
                            ['options'=>['min_range'=>1]]
                        );
                        if($validateId===false) {
                            $error='ID phải là một số nguyên dương.';
                        } else {
                            $book=$db->getBook($id);
                            if(!$book) {
                                $error='Không tìm thấy book với ID đã nhập.';
                            }
                        }
                    }
                }
            }
            $action=$_POST['action']??'';
            if($action==='reserved') {
                if (!$book) {
                    if($error==='') {
                        $error='Không tìm thấy book để cập nhật.';
                    }
                } elseif ($book['status']==='available') {
                    $result=$db->updateBook($book['id'],$book['status'],'reserved');
                    if ($result>0) {
                        $success = 'Book đã được đặt trước thành công.';
                        $book['status'] = 'reserved';
                    } else {
                        $book=$db->getBook($book['id']);
                        if ($book) {
                            $error='Trạng thái hiện tại của book đã thay đổi. Vui lòng thử lại.';
                        } else {
                            $error="Book không còn tồn tại";
                        }
                    }
                } elseif($book['status']==='reserved'||$book['status']==='borrowed') {
                    $error="Book đang ở trạng thái {$book['status']} và không thể cập nhật";
                } else {
                    $error='Trạng thái book không hợp lệ.';
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Lỗi cơ sở dữ liệu. Vui lòng thử lại sau.';
            $book=null;
            $success="";
        }
        require_once __DIR__.'/../views/book_search.php';
    }
}
?>