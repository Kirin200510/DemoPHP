<?php
function myReverse(string $s): string {
    $result=preg_match_all('/\X/u', $s, $matches);
    $result = $matches[0];
    $reversed = implode('', array_reverse($result));
    return $reversed;
}
function normalizeSentence(string $sentence): string {
    $sentence=trim($sentence);
    $sentence=preg_replace('/\s+/u', '', $sentence);
    // bỏ các dấu câu để kiểm tra Palindrome, bỏ ký tự đặc biệt, chỉ giữ lại các ký tự chữ cái và số
    $sentence=preg_replace('/[.,?!]/u', '', $sentence);
    $sentence=preg_replace('/[^\p{L}\p{N}]/u', '', $sentence);
    if ($sentence === '') {
        return '';
    }
    $sentence=trim($sentence);
    return $sentence;
}
// kiểm tra chuỗi có chứa chuỗi con palindrome hay không
function has_Palindrome_In_Sentence(string $sentence):bool {
    $sentence=normalizeSentence($sentence);
    $sentence=trim($sentence);
    $reversedSentence=null;
    // lower 2 chuỗi 
    $reversedSentence=strtolower(myReverse($sentence));
    $sentence=strtolower($sentence);
    // tách 2 chuỗi thành mảng các ký tự
    $reversed=preg_split('//u', $reversedSentence, -1, PREG_SPLIT_NO_EMPTY);
    $original=preg_split('//u', strtolower($sentence), -1, PREG_SPLIT_NO_EMPTY);
    
    $length = count($original);
    for ($i = 0; $i < $length-1; $i++) {
        for ($j = $i + 1; $j < $length; $j++) {
            $substring=substr($sentence, $i, $j - $i + 1);
            $reversedSubstring=myReverse($substring);
            if ($substring === $reversedSubstring) {
                return true;
            }
        }
    }
    return false;
}
function longestPalindrome(string $sentence): string {
    $sentence=normalizeSentence($sentence);
    $longestPalindrome = '';
    $length = strlen($sentence);
    for ($i = 0; $i < $length; $i++) {
        for ($j = $i + 1; $j <= $length; $j++) {
            $substring = substr($sentence, $i, $j - $i);
            if ($substring === myReverse($substring) && strlen($substring) > strlen($longestPalindrome)) {
                $longestPalindrome = $substring;
            }
        }
    }
    return $longestPalindrome;
}

$sentence = "abc-levavavel-cba";
$sentence1="Tô,i đi: ô-i tÔi!";
$sentence2 = "helolo";
$sentence3 = "Hello, world!";
if(has_Palindrome_In_Sentence($sentence1)){
    echo "Câu vừa nhập có chứa Palindrome\n";
    echo "Palindrome dài nhất trong câu là: " . longestPalindrome($sentence1). "\n";
} else {
        echo "Câu vừa nhập không có chứa Palindrome\n";
}
?>
