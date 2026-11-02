<?php
function myReverse(string $s): string {
    $result=preg_split('/\X/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $reversed = implode('', array_reverse($result));
    return $reversed;
}
?>