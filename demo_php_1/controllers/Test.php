<?php
class Controller {
    public function convertToCanChi(int $year): string {
        $can=["Giap","At","Binh","Dinh","Mau","Ky","Canh","Tan","Nham","Quy"];
        $chi=["Ty","Suu","Dan","Meo","Thin","Ty","Ngo","Mui","Than","Dau","Tuat","Hoi"];
        $canIndex=($year-4)%10;
        $chiIndex=($year-4)%12;
        return $can[$canIndex]." ".$chi[$chiIndex];
    }
    public function index() {
        $result= "";
        $error= "";
        $year=null;
        if ($_SERVER['REQUEST_METHOD']=== 'POST') {
            $year=$_POST["year"]??null;
            if($year===null) {
                $error= "Please require input year";
            } elseif(!is_string($year)) {
                $error= "year must be type string";
            } else {
                $year=trim($year);
                if($year=== "") {
                    $error= "Vui long nhap nam";
                } elseif(!ctype_digit($year)) {
                    $error= "Nam khong hop le";
                } else {
                    $year=ltrim($year,"0");
                    $validateYear=filter_var($year, FILTER_VALIDATE_INT,["options"=>["min_range"=>1]]);
                    if ($validateYear===false) { 
                        $error= "Year >0";
                    } else {
                        $year=(int)$validateYear;
                        $result=$this->convertToCanChi($year);
                    }
                }
            }
        }
        require_once "views/convert.php";
    }
}
?>
