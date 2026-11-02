<?php
function processSentence(string $sentence): string {
    //Loại bỏ khoang trắng thừa ở đầu và cuối câu
    $sentence = trim($sentence);
    //Loại bỏ các khoang trắng thừa giữa các từ
    $sentence = preg_replace('/\s+/u', ' ', $sentence);
    if ($sentence === '') {
        return '';
    }
    //Chuẩn hóa dấu câu và cách từ tiếp theo khoảng trắng 
    $sentence=preg_replace(
        '/\s*([.,?!]+)\s*/u', '$1 ',$sentence
    );
    // Loại bỏ khoảng trắng thừa ở cuối câu sau khi chuẩn hóa dấu câu
    $sentence=trim($sentence);
    //Tách câu thành các từ 
    $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
    $reversedWords = array_reverse($words);
    //Ghép các từ lại thành câu mới
    $reversedSentence = implode(' ', $reversedWords);
    $reversedSentence = mb_strtolower($reversedSentence, 'UTF-8');
    if($reversedSentence!=="") {
        $first_char=mb_substr($reversedSentence, 0, 1, 'UTF-8');
        $first_char_upper=mb_strtoupper($first_char, 'UTF-8');
        $reversedSentence=$first_char_upper.mb_substr($reversedSentence, 1, null, 'UTF-8');
    }
    return $reversedSentence;
}
echo "Nhập câu: ";
$sentence = readline();
$reversedSentence = processSentence($sentence);
echo "Câu sau khi đảo ngược: " . $reversedSentence . "\n";
?>
