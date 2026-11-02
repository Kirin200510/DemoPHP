
<?php
function fizzBuzzBang(int $N):void   {
    for ($i = 1; $i <= $N; $i++) {
        $output = '';
        if( $i % 3 === 0) {
            $output .= 'Fizz';
        }
        if( $i % 5 === 0) {
            $output .= 'Buzz';
        }
        if( $i % 7 === 0) {
            $output .= 'Bang';
        }
        
        if ($output === '') {
            $output = $i;
        }
        echo $output;
        echo " ";
    }
}
?>
