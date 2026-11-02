<?php
function isPrime(int $n): bool {
    if ($n <= 1) {
        return false;
    }
    $limit=sqrt($n);
    for ($i = 2; $i <= $limit; $i++) {
        if ($n % $i === 0) {
            return false;
        }
    }
    return true;
}
function showPrime(int $N): void {
    echo "Các số nguyên tố từ 1 đến $N là: ";
    for ($i = 2; $i <= $N; $i++) {
        if (isPrime($i)) {
            echo "$i ";
        }
    }
    echo "\n";
}
echo "Nhập số nguyên N: ";
$N = (int)readline();
showPrime($N);
?>