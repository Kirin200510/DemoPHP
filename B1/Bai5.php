<?php
//Đếm tần suất xuất hiện của các số trong mảng
function countNumberTimes(array $numbers): array {
    $countedNumbers = [];
    foreach ($numbers as $number) {
        if (isset($countedNumbers[$number])) {
            $countedNumbers[$number]++;
        } else {
            $countedNumbers[$number] = 1;
        }
    }
    return $countedNumbers;
}
function showNumberHighestTimes(array $numbers): void {
    $countedNumbers = countNumberTimes($numbers);
    $maxCount=max($countedNumbers);
    $mostFrequentNumbers=array_keys($countedNumbers, $maxCount);
    echo "Các số xuất hiện nhiều nhất: " . implode(', ', $mostFrequentNumbers) . " .Với số lần xuất hiện là: $maxCount" . "\n";
}
echo "Nhập các số nguyên, cách nhau bằng dấu phẩy: ";
$input = readline();
print_r($input);
showNumberHighestTimes(explode(',', $input));

?>