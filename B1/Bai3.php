<?php
function myReverse(string $s): string {
    $result=preg_match_all('/\X/u', $s, $matches);
    $result = $matches[0];
    $reversed = implode('', array_reverse($result));
    return $reversed;
}
function palindrome($sentence):bool {
    $sentence=trim($sentence);
    $sentence=preg_replace('/\s+/u', '', $sentence);
    // bỏ các dấu câu để kiểm tra Palindrome, bỏ ký tự đặc biệt, chỉ giữ lại các ký tự chữ cái và số
    $sentence=preg_replace('/[.,?!]/u', '', $sentence);
    $sentence=preg_replace('/[^\p{L}\p{N}]/u', '', $sentence);
    if ($sentence === '') {
        return false; 
    }
    $reversedSentence=null;
    $reversedSentence=strtolower(myReverse($sentence));
    return strtolower($sentence) === $reversedSentence;
}
echo("Nhập câu: ");
$sentence = readline();
if(palindrome($sentence)){
    echo "Câu vừa nhập là câu Palindrome\n";
}else{
    echo "Câu vừa nhập không phải là câu Palindrome\n";
}
?>