<?php
function h(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES,"UTF-8");
}
?>
<html>
    <head>Convert to Can Chi</head>
    <body>
        <form method="post">
            <input type="text" name="year" value="<?=h(is_string($_POST['year']??null)?$_POST['year']:'')?>">
            <button type="submit">Convert</button>
        </form>
        <?php if ($error!==""):?>
            <script>alert(<?=json_encode($error,JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG)?>)</script>
        <?php endif;?>
        <?php if ($result!==""):?>
        <p>Ket qua chuyen doi: <?=h($result)?></p>
        <?php endif?>
    </body>
</html>