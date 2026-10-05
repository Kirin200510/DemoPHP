<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
// Khai báo trạng thái đặt vé và ghế
define('BOOKING_PENDING', 'pending'); define('BOOKING_PAID', 'paid'); define('BOOKING_CANCELLED', 'cancelled');
define('SEAT_AVAILABLE', 'available'); define('SEAT_RESERVED', 'reserved'); define('SEAT_SOLD', 'sold');
// Khai báo biến toàn cục
$db = null;
$currentUser = null;
$errors = array();
$messages = array();
// Tạo kết nối db
function db()
{
    global $db;
    if ($db !== null) {
        return $db;
    }
    try {
        $db = new PDO(
            'mysql:host=127.0.0.1;dbname=movie_booking;charset=utf8mb4',
            'root',
            ''
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (Exception $e) {
        die('Database error: ' . $e->getMessage());
    }
}
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function addError($message)
{
    global $errors;
    $errors[] = $message;
}
function addMessage($message)
{
    global $messages;
    $messages[] = $message;
}
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}
function getAction()
{
    if (isset($_POST['action'])) {
        return trim($_POST['action']);
    }
    if (isset($_GET['action'])) {
        return trim($_GET['action']);
    }
    return 'view';
}
function requireLogin()
{
    global $currentUser;
    // Kiểm tra xem người dùng đã đăng nhập chưa
    if (!isset($_SESSION['user_id'])) {
        redirect('login.php');
    }
    // việc câu truy vấn
    $stmt = db()->prepare(
        'SELECT id, full_name, email, active
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    // Thực thi truy vấn
    $stmt->execute(array(':id' => $_SESSION['user_id']));
    $currentUser = $stmt->fetch();
    if (!$currentUser) {
        session_destroy();
        redirect('login.php?error=invalid_session');
    }
    if ((int) $currentUser['active'] !== 1) {
        session_destroy();
        redirect('login.php?error=inactive');
    }
}
function findShowtime($showtimeId)
{
    $stmt = db()->prepare(
        'SELECT s.id, s.movie_id, s.room_id, s.start_time,
                m.title, m.duration, r.name AS room_name
         FROM showtimes s
         JOIN movies m ON m.id = s.movie_id
         JOIN rooms r ON r.id = s.room_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute(array(':id' => $showtimeId));
    // Trả về thông tin suất chiếu hoặc false nếu không tìm thấy
    return $stmt->fetch();
}
function findSeats($showtimeId)
{
    $stmt = db()->prepare(
        'SELECT ss.seat_id, ss.status, ss.price,
                s.seat_code, s.seat_type
         FROM showtime_seats ss
         JOIN seats s ON s.id = ss.seat_id
         WHERE ss.showtime_id = :showtime_id
         ORDER BY s.seat_code'
    );
    $stmt->execute(array(':showtime_id' => $showtimeId));
    return $stmt->fetchAll();
}
function isSeatAvailable($showtimeId, $seatId)
{
    $stmt = db()->prepare(
        'SELECT id
         FROM showtime_seats
         WHERE showtime_id = :showtime_id
           AND seat_id = :seat_id
           AND status = :status
         LIMIT 1'
    );
    $stmt->execute(array(
        ':showtime_id' => $showtimeId,
        ':seat_id' => $seatId,
        ':status' => SEAT_AVAILABLE
    ));
    return $stmt->fetch() !== false;
}
function calculateTotal($showtimeId, $seatIds)
{
    $total = 0;
    foreach ($seatIds as $seatId) {
        $stmt = db()->prepare(
            'SELECT price
             FROM showtime_seats
             WHERE showtime_id = :showtime_id
               AND seat_id = :seat_id
             LIMIT 1'
        );
        $stmt->execute(array(
            ':showtime_id' => $showtimeId,
            ':seat_id' => $seatId
        ));
        $seat = $stmt->fetch();
        if ($seat) {
            $total += (float) $seat['price'];
        }
    }
    return $total;
}
function calculateDiscount($total, $coupon)
{
    $coupon = strtoupper(trim($coupon));
    if ($coupon === 'MOVIE10') {
        return $total * 0.10;
    }
    if ($coupon === 'WEEKEND50' && $total >= 300000) {
        return 50000;
    }
    return 0;
}
function createBooking($userId, $showtimeId, $total, $discount, $finalAmount)
{
    $stmt = db()->prepare(
        'INSERT INTO bookings
        (user_id, showtime_id, total_amount, discount_amount,
         final_amount, status, created_at)
        VALUES
        (:user_id, :showtime_id, :total, :discount,
         :final_amount, :status, NOW())'
    );
    $stmt->execute(array(
        ':user_id' => $userId,
        ':showtime_id' => $showtimeId,
        ':total' => $total,
        ':discount' => $discount,
        ':final_amount' => $finalAmount,
        ':status' => BOOKING_PENDING
    ));
    return db()->lastInsertId();
}
function reserveSeat($bookingId, $showtimeId, $seatId)
{
    $stmt = db()->prepare(
        'UPDATE showtime_seats
         SET status = :reserved, booking_id = :booking_id
         WHERE showtime_id = :showtime_id
           AND seat_id = :seat_id
           AND status = :available'
    );
    $stmt->execute(array(
        ':reserved' => SEAT_RESERVED,
        ':booking_id' => $bookingId,
        ':showtime_id' => $showtimeId,
        ':seat_id' => $seatId,
        ':available' => SEAT_AVAILABLE
    ));
    return $stmt->rowCount() > 0;
}
function findBooking($bookingId, $userId)
{
    $stmt = db()->prepare(
        'SELECT id, user_id, showtime_id, total_amount,
                discount_amount, final_amount, status, created_at
         FROM bookings
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(array(':id' => $bookingId, ':user_id' => $userId));
    return $stmt->fetch();
}
function markBookingPaid($bookingId, $userId)
{
    $stmt = db()->prepare(
        'UPDATE bookings
         SET status = :paid
         WHERE id = :id
           AND user_id = :user_id
           AND status = :pending'
    );
    $stmt->execute(array(
        ':paid' => BOOKING_PAID,
        ':id' => $bookingId,
        ':user_id' => $userId,
        ':pending' => BOOKING_PENDING
    ));
    // Trả về true nếu cập nhật, false nếu không
    return $stmt->rowCount() > 0;
}
function markSeatsSold($bookingId)
{
    $stmt = db()->prepare(
        'UPDATE showtime_seats
         SET status = :sold
         WHERE booking_id = :booking_id
           AND status = :reserved'
    );
    return $stmt->execute(array(
        ':sold' => SEAT_SOLD,
        ':booking_id' => $bookingId,
        ':reserved' => SEAT_RESERVED
    ));
}
function cancelBooking($bookingId, $userId)
{
    $stmt = db()->prepare(
        'UPDATE bookings
         SET status = :cancelled
         WHERE id = :id
           AND user_id = :user_id
           AND status = :pending'
    );
    $stmt->execute(array(
        ':cancelled' => BOOKING_CANCELLED,
        ':id' => $bookingId,
        ':user_id' => $userId,
        ':pending' => BOOKING_PENDING
    ));
    return $stmt->rowCount() > 0;
}
function releaseSeats($bookingId)
{
    $stmt = db()->prepare(
        'UPDATE showtime_seats
         SET status = :available, booking_id = NULL
         WHERE booking_id = :booking_id
           AND status = :reserved'
    );
    return $stmt->execute(array(
        ':available' => SEAT_AVAILABLE,
        ':booking_id' => $bookingId,
        ':reserved' => SEAT_RESERVED
    ));
}
/* ===================== MAIN FLOW ===================== */
requireLogin();
// Xác định request method và action
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = getAction();
$showtimeId = 0;
$bookingId = 0;
if (isset($_GET['showtime_id'])) {
    $showtimeId = (int) $_GET['showtime_id'];
} elseif (isset($_POST['showtime_id'])) {
    $showtimeId = (int) $_POST['showtime_id'];
}
if (isset($_GET['booking_id'])) {
    $bookingId = (int) $_GET['booking_id'];
} elseif (isset($_POST['booking_id'])) {
    $bookingId = (int) $_POST['booking_id'];
}
if ($showtimeId <= 0) {
    http_response_code(400);
    die('Invalid showtime.');
}
$showtime = findShowtime($showtimeId);
if (!$showtime) {
    http_response_code(404);
    die('Showtime not found.');
}
$seats = findSeats($showtimeId);
$currentBooking = null;
/* ===================== POST ROUTER ===================== */
if ($requestMethod === 'POST') {
    switch ($action) {
        // giữ ghế
        case 'reserve_seats': 
            $seatIds = isset($_POST['seat_ids']) ? $_POST['seat_ids'] : array();
            $coupon = isset($_POST['coupon']) ? $_POST['coupon'] : '';
            if (empty($seatIds)) {
                addError('Please select at least one seat.');
                break;
            }
            foreach ($seatIds as $seatId) {
                // kiểm tra ghế trống
                if (!isSeatAvailable($showtimeId, $seatId)) {
                    addError('One selected seat is no longer available.');
                    break 2;
                }
            }
            $total = calculateTotal($showtimeId, $seatIds);
            $discount = calculateDiscount($total, $coupon);
            if ($coupon !== '' && $discount <= 0) {
                addError('Coupon is invalid or not eligible.');
                break;
            }
            $finalAmount = $total - $discount;
            $bookingId = createBooking(
                $currentUser['id'],
                $showtimeId,
                $total,
                $discount,
                $finalAmount
            );
            foreach ($seatIds as $seatId) {
                // reserveSeat hàm chuyển ghế sang trạng thái giữ
                if (!reserveSeat($bookingId, $showtimeId, $seatId)) {
                    addError('Seat reservation failed.');
                    break;
                }
            }
            if (empty($errors)) {
                addMessage('Seats reserved successfully.');
                $currentBooking = findBooking(
                    $bookingId,
                    $currentUser['id']
                );
            }
            break;
        case 'pay':
            if ($bookingId <= 0) {
                addError('Invalid booking.');
                break;
            }
            $currentBooking = findBooking(
                $bookingId,
                $currentUser['id']
            );
            if (!$currentBooking) {
                addError('Booking not found.');
                break;
            }
            if ($currentBooking['status'] !== BOOKING_PENDING) {
                addError('Booking is not available for payment.');
                break;
            }
            // Mock payment gateway result.
            $paymentSuccess = true;
            if (!$paymentSuccess) {
                addError('Payment failed.');
                break;
            }
            if (markBookingPaid($bookingId, $currentUser['id'])) {
                markSeatsSold($bookingId);
                addMessage('Payment completed successfully.');
                // render lại thông tin booking
                $currentBooking = findBooking(
                    $bookingId,
                    $currentUser['id']
                );
            } else {
                addError('Could not update booking status.');
            }
            break;
        case 'cancel':
            if ($bookingId <= 0) {
                addError('Invalid booking.');
                break;
            }
            $currentBooking = findBooking(
                $bookingId,
                $currentUser['id']
            );
            if (!$currentBooking) {
                addError('Booking not found.');
                break;
            }
            if ($currentBooking['status'] !== BOOKING_PENDING) {
                addError('Only pending bookings can be cancelled.');
                break;
            }
            if (cancelBooking($bookingId, $currentUser['id'])) {
                releaseSeats($bookingId);
                // render lại thông tin booking
                addMessage('Booking cancelled.');
                $currentBooking = findBooking(
                    $bookingId,
                    $currentUser['id']
                );
            } else {
                addError('Could not cancel booking.');
            }
            break;
        default:
            addError('Unknown action.');
            break;
    }
}
/* ===================== VIEW DATA ===================== */
// Render UI với dữ liệu mới
$seats = findSeats($showtimeId);

if ($bookingId > 0 && !$currentBooking) {
    $currentBooking = findBooking(
        $bookingId,
        $currentUser['id']
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Legacy Movie Booking</title>
<style>
body{font-family:Arial;margin:30px}
.box{border:1px solid #ccc;padding:12px;margin-bottom:15px}
.error{color:#b00020}.success{color:#087a23}
.seat{display:inline-block;margin:4px;padding:6px;border:1px solid #999}
</style>
</head>
<body>
<h1><?php echo h($showtime['title']); ?></h1>
<div class="box">
User: <?php echo h($currentUser['full_name']); ?><br>
Room: <?php echo h($showtime['room_name']); ?><br>
Start: <?php echo h($showtime['start_time']); ?><br>
Duration: <?php echo h($showtime['duration']); ?> minutes
</div>
<?php if (!empty($errors)): ?>
<div class="box error">
<?php foreach ($errors as $error): ?>
<div><?php echo h($error); ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!empty($messages)): ?>
<div class="box success">
<?php foreach ($messages as $message): ?>
<div><?php echo h($message); ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<div class="box">
<h2>Select seats</h2>
<form method="post">
<input type="hidden" name="action" value="reserve_seats">
<input type="hidden" name="showtime_id" value="<?php echo h($showtimeId); ?>">
<?php foreach ($seats as $seat): ?>
<label class="seat">
<input type="checkbox" name="seat_ids[]" value="<?php echo h($seat['seat_id']); ?>" <?php echo $seat['status'] !== SEAT_AVAILABLE ? 'disabled' : ''; ?>>
<?php echo h($seat['seat_code']); ?>
- <?php echo number_format($seat['price']); ?>
- <?php echo h($seat['status']); ?>
</label>
<?php endforeach; ?>
<br><br>
<label>Coupon: <input type="text" name="coupon"></label>
<button type="submit">Reserve seats</button>
</form>
</div>
<?php if ($currentBooking): ?>
<div class="box">
<h2>Current booking</h2>
<p>ID: <?php echo h($currentBooking['id']); ?></p>
<p>Status: <?php echo h($currentBooking['status']); ?></p>
<p>Total: <?php echo number_format($currentBooking['total_amount']); ?></p>
<p>Discount: <?php echo number_format($currentBooking['discount_amount']); ?></p>
<p>Final: <?php echo number_format($currentBooking['final_amount']); ?></p>
<?php if ($currentBooking['status'] === BOOKING_PENDING): ?>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="pay">
<input type="hidden" name="showtime_id" value="<?php echo h($showtimeId); ?>">
<input type="hidden" name="booking_id" value="<?php echo h($currentBooking['id']); ?>">
<button type="submit">Pay</button>
</form>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="cancel">
<input type="hidden" name="showtime_id" value="<?php echo h($showtimeId); ?>">
<input type="hidden" name="booking_id" value="<?php echo h($currentBooking['id']); ?>">
<button type="submit">Cancel booking</button>
</form>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="box">
Request: <?php echo h($requestMethod); ?> |
Action: <?php echo h($action); ?> |
Showtime ID: <?php echo h($showtimeId); ?> |
Booking ID: <?php echo h($bookingId); ?>
</div>
</body>
</html>
