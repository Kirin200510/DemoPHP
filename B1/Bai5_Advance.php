<?php
function flattenNumberArray(array $array): array {
    $result = [];
    foreach ($array as $value) {
        if (is_array($value)) {
            $result = array_merge($result, flattenNumberArray($value));
        } elseif (is_numeric($value)) {
            $result[] = (int)$value;
        }
    }
    return $result;
}
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
function topKCountNumberTimes(array $numbers, int $k): array {
    $countedNumbers = countNumberTimes($numbers);
    arsort($countedNumbers);
    return array_slice($countedNumbers, 0, $k, true);
}
$input = [
    [3, 1, 4],
    [1, 5, [9, 2]],
    [6, 5, 3, 5],
    "3", "1", "4" // Chuỗi số
];

if (is_array($input)) {
    $flattenedArray = flattenNumberArray($input);
    $countedNumbers = countNumberTimes($flattenedArray);
    echo "Mảng sau khi làm phẳng: ";
    print_r($flattenedArray);
    echo "\n";
    echo "Số lần xuất hiện của từng số trong mảng: ";
    print_r($countedNumbers);
    echo "\n";
    echo "Top 4 số xuất hiện nhiều nhất: ";
    $top4 = topKCountNumberTimes($flattenedArray, 4);
    print_r($top4);

} else {
    echo "Dữ liệu đầu vào không phải là mảng.\n";
}
?>