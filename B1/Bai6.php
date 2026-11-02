<?php
//Điều kiện dừng base case là điều kiện cơ sở để ngừng đệ quy
//Nêu thiếu điều kiện dừng thì đệ quy  sẽ gọi hàm với n giảm dần(n-1, n-2) đến khi tràn bộ nhớ
function fibonacci(int $n): int {
    if ($n <= 0) {
        return 0;
    } elseif ($n === 1) {
        return 1;
    } else {
        return fibonacci($n - 1) + fibonacci($n - 2);
    }
}
echo "Nhập số nguyên n: ";
$n = (int)readline();
if ($n < 0) {
    echo "Vui lòng nhập một số nguyên không âm.\n";
} else {
    $result = fibonacci($n);
    echo "Số Fibonacci thứ $n là: $result\n";
}
?>