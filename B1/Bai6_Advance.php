<?php
function climbingStairWithMemo(int $n, array &$memo):int {
    if ($n <= 0) {
        return 0;
    }
    if ($n <=2 && $n > 0) {
        return $n;
    }
    if (isset($memo[$n])) {
        return $memo[$n];
    }

    $memo[$n] = climbingStairWithMemo($n - 1, $memo) + climbingStairWithMemo($n - 2, $memo);
    return $memo[$n];
}
echo "Nhập số bậc thang n: ";
$n = (int)readline();
if ($n < 0) {
    echo "Vui lòng nhập một số nguyên không âm.\n";
} else {
    $memo = [];
    $result = climbingStairWithMemo($n, $memo);
    echo "Số cách leo lên $n bậc thang là: $result\n";
}
//- Cơ chế Memoization khắc phục điểm yếu gì của đệ quy thuần túy? là 
?>