<?php
function isPrime(int $n): bool {
    if ($n <= 1) {
        return false;
    }
    $limit= sqrt($n);
    for ($i = 2; $i <= $limit; $i++) {
        if ($n % $i === 0) {
            return false;
        }
    }
    return true;
}
function FooBarBazPrime(int $N):void   {
    for ($i = 1; $i <= $N; $i++) {
        $output = '';
        if( $i % 2 === 0) {
            $output .= 'Foo';
        }
        if( $i % 3 === 0) {
            $output .= 'Bar';
        }
        if( $i % 5 === 0) {
            $output .= 'Baz';
        }
        if( isPrime($i)) {
            $output .= 'Prime';
        }
        
        if ($output === '') {
            $output = $i;
        }
        echo $output;
        echo "\n";
    }
}
$N = (int)readline("Nhập số N: ");
FooBarBazPrime($N);
?>
