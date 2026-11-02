<?php
function eratosthenes(int $N):array {
    $number_prime=array_fill(0,$N+1,true);
    $number_prime[0]=false;
    $number_prime[1]=false;
    $p=2;
    while ($p*$p<=$N) {
        if ($number_prime[$p]) {
            for ($i = $p * $p; $i <= $N; $i += $p) {
                $number_prime[$i] = false;
            }
        }
        $p++;
    }
    return $number_prime;
}
function showPrimes(int $N): void {
    $number_prime=eratosthenes($N);
    echo "Các số nguyên tố từ 1 đến $N là: ";
    for ($i = 2; $i <= $N; $i++) {
        if ($number_prime[$i]) {
            echo "$i ";
        }
    }
    echo "\n";
}
function primeFactor(int $n): array {
    $number_prime=eratosthenes($n);
    $count_factors = [];
    for ($i = 2; $i <= $n; $i++) {
        if ($number_prime[$i] && $n % $i === 0) {
            $count = 0;
            while ($n % $i === 0) {
                $n /= $i;
                $count++;
            }
            $count_factors[$i] = $count;
        }
    }
    return $count_factors;
}

echo "Nhập số nguyên N: ";
$N = (int)readline();
showPrimes($N);
$factors = primeFactor($N);
echo "Số lượng thừa số nguyên tố của $N là: ";
foreach ($factors as $prime => $count) {
    echo "$prime^$count ";
}
echo "\n";
//Thuật toán sàng Eratosthenes tối ưu hơn thuật toán kiểm tra số nguyên tố từng số, 
// vì nó chỉ cần duyệt qua các số từ 2 đến căn bậc hai của N, và đánh dấu các bội số của các số nguyên tố đã tìm được để loại bỏ.
//  Còn thuật toán kiểm tra số nguyên tố từng số phải kiểm tra từng số từ 2 đến căn bậc hai của số đó, nên sẽ tốn nhiều thời gian hơn khi N lớn chạy vòng lặp lâu hơn.
