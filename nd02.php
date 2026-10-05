<?php

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function db()
{
    $host = "localhost";
    $dbname = "php_test";
    $dbUser = "phpuser";
    $dbPassword = "123456";

    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPassword);

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        
        return $pdo;

    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

$pdo = db();

$user = null;
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = trim($_POST["id"] ?? "");

    if ($id === "") {
        $error = "User ID is required.";
    } elseif (!ctype_digit($id)) {
        $error = "User ID must be a number.";
    } elseif ((int)$id <= 0) {
        $error = "User ID must be greater than 0.";
    } else {

        $sql = "
            SELECT id, name, email
            FROM users
            WHERE id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = "User not found.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP PDO Demo</title>
</head>
<body>

<h1>Find User</h1>

<form method="POST">

    <input
        type="text"
        name="id"
        placeholder="Enter user ID"
        value="<?= h($_POST["id"] ?? "") ?>"
    >

    <button type="submit">Search</button>

</form>

<?php if ($error !== ""): ?>
    <p><?= h($error) ?></p>
<?php endif; ?>

<?php if ($user): ?>
    <p>ID: <?= h($user["id"]) ?></p>
    <p>Name: <?= h($user["name"]) ?></p>
    <p>Email: <?= h($user["email"]) ?></p>
<?php endif; ?>

</body>
</html>