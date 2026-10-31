<?php
function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES,'UTF-8');
}
?>
<html>
    <head>
        <title>BookSearch</title>
    </head>
    <body>
        <h1>Book Search</h1>
        <form method="post">
            <label for="id">ID:</label>
            <input type="text" name="id" id="id" value="<?= h(is_string($_POST['id']??null)? $_POST['id'] : '') ?>">
            <button type="submit">Search</button>
        </form>
        <?php if ($error!==""): ?>
            <script>alert(json_encode($error,JSON_HEX_TAG| JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS))</script>
        <?php endif?>
        <?php if ($success!== ""): ?>
            <script>alert(json_encode($success,JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG))</script>
        <?php endif ?>
        <?php if ($book): ?>
            <h2>Book Details</h2>
            <p>ID: <?=h($book['id'])?></p>
            <p>Title: <?=h($book['title'])?></p>
            <p>Status: <?=h($book['status'])?></p>
            <?php if($book['status']==='available'): ?>
                <form method="post">
                    <input type="hidden" name="id" value="<?= h($book['id'])?>">
                    <input type="hidden" name="action" value="update_status">
                    <button type="submit">Update Status</button>
                </form>
            <?php endif ?>
        <?php endif ?>

    </body>
</html>