<?php
class Test {
    public function validateRange(string $num):bool {
        $num=trim($num);
        $num=ltrim($num, '0');
        $validateNum=filter_var($num, FILTER_VALIDATE_INT, ['options'=>["min_range"=>1]]);
        return $validateNum !== false;
    }
    public function valdateInput(string $input):string {
        $input=trim($input);
        if($input==="") {
            return "Input is empty";
        } 
        if(preg_match('/^\d+[.]\d+$/', $input)) {
            return "Năm phải là số nguyên";
        }
        elseif(ctype_digit($input)) {
            if(!$this->validateRange($input)) {
                return "Năm phải là số nguyên dương";
            } else {
                return "Valid input";
            }
        }
        elseif(str_contains($input, ',')) {
            $years=explode(',', $input);
            if (count($years) > 20) {
                return "Số lượng năm không được vượt quá 20";
            }
            foreach($years as $year) {
                $year=trim($year);
                if(!ctype_digit($year)) {
                    return "Phần tử không hợp lệ";
                } elseif(!$this->validateRange($year)) {
                    return "Năm phải là số nguyên dương";
                }
            }
            return "Valid input";
        } elseif(str_contains($input, '-')) {
            if(preg_match('/^[-]\d+$/', $input)) {
                return "Năm không được âm";
            }
            $years=explode('-', $input);
            if(count($years) !== 2) {
                return "Phần tử không hợp lệ";
            }
            $startYear=trim($years[0]);
            $endYear=trim($years[1]);
            if(!ctype_digit($startYear) || !ctype_digit($endYear)) {
                return "Khoảng năm không hợp lệ";
            } elseif(!$this->validateRange($startYear) || !$this->validateRange($endYear)) {
                return "Năm phải là số nguyên dương";
            } elseif((int)ltrim($startYear, '0') > (int)ltrim($endYear, '0')) {
                return "Năm bắt đầu phải nhỏ hơn hoặc bằng năm kết thúc";
            } elseif((int)ltrim($endYear, '0') - (int)ltrim($startYear, '0') > 100) {
                return "Khoảng năm không được vượt quá 100 năm";
            }
            else {
                return "Valid input";
            }
        } else {
            return "Dữ liệu không hợp lệ";
        }
    }
    public function parseInput(string $input):array {
        $years=[];
        $input=trim($input);
        if(ctype_digit($input)) {
            $years[]=(int)ltrim($input, '0');
        } elseif(str_contains($input, ',')) {
            $results=explode(',', $input);
            foreach($results as $year) {
                $year=trim($year);
                $year=(int)ltrim($year, '0');
                $years[]=$year;
            }
        } elseif(str_contains($input, '-')) {
            $results=explode('-', $input);
            $startYear=trim($results[0]);
            $endYear=trim($results[1]);
            $startYear=(int)ltrim($startYear, '0');
            $endYear=(int)ltrim($endYear, '0');
            for($year=$startYear; $year<=$endYear; $year++) {
                $years[]=$year;
            }
        }
        else {
            return [];
        }
        return $years;
    }
    public function convertToCanChi(int $year):string {
        $can=array("Giáp", "Ất", "Bính", "Đinh", "Mậu", "Kỷ","Canh", "Tân", "Nhâm", "Quý");
        $chi=array("Tý", "Sửu", "Dần", "Mão", "Thìn", "Tỵ", "Ngọ", "Mùi","Thân", "Dậu", "Tuất", "Hợi");
        $canIndex=(($year-1984)%10+10)%10;
        $chiIndex=(($year-1984)%12+12)%12;
        return $can[$canIndex]." ".$chi[$chiIndex];
    }
    public function processYears(array $years):array {
        $results=[];
        foreach($years as $year) {
            $results[$year]=$this->convertToCanChi($year);
        }
        return $results;
    }
    public function getStatistic(array $years):array {
        $totalYears=count($years);
        $uniqueCanChi=array_values(array_unique($years));
        $unqueCount=count($uniqueCanChi);
        $duplicateCanChi=$totalYears-$unqueCount;
        return [
            "totalYears"=>$totalYears,
            "uniqueCanChi"=>$unqueCount,
            "duplicateCanChi"=>$duplicateCanChi
        ];
    }
    public function findSameCanChi(array $years,int $nam):array {
        $results=[];
        foreach($years as $year) {
            if(($nam-$year)%60===0) {
                $results[]=$year;
            }
        }
        return $results;
    }
    public function index() {
        $error="";
        $validationMessage="";
        $validationNamMessage="";
        $results=[];
        $input="";
        $years= [];
        $sameCanChiYears=[];
        $statistic=[];
        $nam="";
        if($_SERVER['REQUEST_METHOD']==='POST') {
            $input=$_POST['input'] ?? '';
            $nam=$_POST['nam'] ?? '';
            $validationMessage=$this->valdateInput($input);
            $validationNamMessage=$this->valdateInput($nam);
            if($validationMessage==="Valid input" && $validationNamMessage==="Valid input") {
                $years=$this->parseInput($input);
                $results=$this->processYears($years);
                $sameCanChiYears=$this->findSameCanChi($years, (int)ltrim(trim($nam, '0')));
                $statistic=$this->getStatistic($years);
            } else {
                $error="Input 1: ".$validationMessage.", Input 2: ".$validationNamMessage;
            }
        }
        require_once 'views/convert.php';
    }
}
?>