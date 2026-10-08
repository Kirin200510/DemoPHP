import cv2
import numpy as np


# =========================================================
# CONFIG
# =========================================================

OUTPUT_DIR = "output"

# Crop phải chạy trước UVDoc. UVDoc có thể tự thay đổi/cắt sát mép ảnh, làm
# mất chính các góc mà bước này cần để xác định giấy tờ.
INPUT_IMAGE = f"{OUTPUT_DIR}/orientation_corrected.jpg"

DETECTION_PREVIEW = f"{OUTPUT_DIR}/document_detection.jpg"
DOCUMENT_MASK = f"{OUTPUT_DIR}/document_mask.jpg"
DOCUMENT_CROP = f"{OUTPUT_DIR}/document_crop.jpg"


# Tỷ lệ hình chữ nhật gần giống CCCD
DOCUMENT_RATIO_MIN = 1.35
DOCUMENT_RATIO_MAX = 2.20

# Tỷ lệ diện tích tối thiểu
MIN_AREA_RATIO = 0.08


# =========================================================
# ORDER 4 POINTS
# =========================================================

def order_points(points):
    """
    Trả về bốn đỉnh theo thứ tự TL, TR, BR, BL.

    Không dùng min/max của ``x + y`` và ``x - y`` vì với hình thang bị
    nghiêng mạnh hai phép đó có thể chọn cùng một đỉnh cho hai vị trí khác
    nhau. Khi đó ma trận perspective bị suy biến và crop bị sai.
    """

    points = np.asarray(
        points,
        dtype=np.float32
    ).reshape(
        -1,
        2
    )

    if len(points) != 4:
        raise ValueError(
            "Document phải có đúng bốn góc."
        )

    if len(np.unique(points, axis=0)) != 4:
        raise ValueError(
            "Bốn góc document phải là các điểm khác nhau."
        )

    sorted_by_y = points[
        np.argsort(points[:, 1])
    ]

    top_points = sorted_by_y[:2]
    bottom_points = sorted_by_y[2:]

    top_left, top_right = top_points[
        np.argsort(top_points[:, 0])
    ]

    bottom_left, bottom_right = bottom_points[
        np.argsort(bottom_points[:, 0])
    ]

    return np.array(
        [
            top_left,
            top_right,
            bottom_right,
            bottom_left
        ],
        dtype=np.float32
    )


# =========================================================
# PERSPECTIVE CROP
# =========================================================

def perspective_crop(
    image,
    points,
    padding_ratio=0.03
):

    points = order_points(
        points
    )

    center = np.mean(
        points,
        axis=0
    )

    # -----------------------------------------------------
    # Add a little margin
    # -----------------------------------------------------

    expanded_points = []

    for point in points:

        direction = point - center

        expanded_point = (
            center
            + direction
            * (1 + padding_ratio)
        )

        expanded_points.append(
            expanded_point
        )

    points = np.array(
        expanded_points,
        dtype=np.float32
    )

    points[:, 0] = np.clip(
        points[:, 0],
        0,
        image.shape[1] - 1
    )

    points[:, 1] = np.clip(
        points[:, 1],
        0,
        image.shape[0] - 1
    )

    # -----------------------------------------------------
    # Width
    # -----------------------------------------------------

    width_top = np.linalg.norm(
        points[1] - points[0]
    )

    width_bottom = np.linalg.norm(
        points[2] - points[3]
    )

    max_width = int(
        max(
            width_top,
            width_bottom
        )
    )

    # -----------------------------------------------------
    # Height
    # -----------------------------------------------------

    height_left = np.linalg.norm(
        points[3] - points[0]
    )

    height_right = np.linalg.norm(
        points[2] - points[1]
    )

    max_height = int(
        max(
            height_left,
            height_right
        )
    )

    if (
        max_width <= 0
        or max_height <= 0
    ):
        raise RuntimeError(
            "Kích thước crop không hợp lệ."
        )

    # -----------------------------------------------------
    # Destination
    # -----------------------------------------------------

    destination = np.array(
        [
            [0, 0],
            [max_width - 1, 0],
            [
                max_width - 1,
                max_height - 1
            ],
            [0, max_height - 1]
        ],
        dtype=np.float32
    )

    # -----------------------------------------------------
    # Perspective transform
    # -----------------------------------------------------

    matrix = cv2.getPerspectiveTransform(
        points,
        destination
    )

    cropped = cv2.warpPerspective(
        image,
        matrix,
        (
            max_width,
            max_height
        )
    )

    return cropped


# =========================================================
# CHECK ANGLES
# =========================================================

def calculate_angle(
    p1,
    p2,
    p3
):

    a = p1 - p2
    b = p3 - p2

    denominator = (
        np.linalg.norm(a)
        * np.linalg.norm(b)
    )

    if denominator == 0:
        return 0.0

    cosine = np.dot(
        a,
        b
    ) / denominator

    cosine = np.clip(
        cosine,
        -1.0,
        1.0
    )

    angle = np.degrees(
        np.arccos(cosine)
    )

    return angle


# =========================================================
# SCORE QUADRILATERAL
# =========================================================

def score_quadrilateral(
    contour,
    points,
    image_shape
):

    image_height = image_shape[0]
    image_width = image_shape[1]

    image_area = (
        image_height
        * image_width
    )

    area = cv2.contourArea(
        contour
    )

    # -----------------------------------------------------
    # Area
    # -----------------------------------------------------

    if area < image_area * MIN_AREA_RATIO:
        return None

    if area > image_area * 0.95:
        return None

    # -----------------------------------------------------
    # Bounding rectangle
    # -----------------------------------------------------

    rect = cv2.minAreaRect(
        contour
    )

    width, height = rect[1]

    if (
        width <= 0
        or height <= 0
    ):
        return None

    ratio = (
        max(width, height)
        / min(width, height)
    )

    if (
        ratio < DOCUMENT_RATIO_MIN
        or ratio > DOCUMENT_RATIO_MAX
    ):
        return None

    # -----------------------------------------------------
    # Rectangularity
    # -----------------------------------------------------

    box_area = width * height

    rectangularity = (
        area / box_area
    )

    if rectangularity < 0.45:
        return None

    # -----------------------------------------------------
    # Order points
    # -----------------------------------------------------

    ordered = order_points(
        points
    )

    # -----------------------------------------------------
    # Width / height similarity
    # -----------------------------------------------------

    top_width = np.linalg.norm(
        ordered[1]
        - ordered[0]
    )

    bottom_width = np.linalg.norm(
        ordered[2]
        - ordered[3]
    )

    left_height = np.linalg.norm(
        ordered[3]
        - ordered[0]
    )

    right_height = np.linalg.norm(
        ordered[2]
        - ordered[1]
    )

    if (
        top_width <= 0
        or bottom_width <= 0
        or left_height <= 0
        or right_height <= 0
    ):
        return None

    width_similarity = (
        min(
            top_width,
            bottom_width
        )
        /
        max(
            top_width,
            bottom_width
        )
    )

    height_similarity = (
        min(
            left_height,
            right_height
        )
        /
        max(
            left_height,
            right_height
        )
    )

    if width_similarity < 0.60:
        return None

    if height_similarity < 0.60:
        return None

    # -----------------------------------------------------
    # Corner angle check
    # -----------------------------------------------------

    angles = []

    for i in range(4):

        p1 = ordered[
            (i - 1) % 4
        ]

        p2 = ordered[i]

        p3 = ordered[
            (i + 1) % 4
        ]

        angle = calculate_angle(
            p1,
            p2,
            p3
        )

        angles.append(
            angle
        )

    average_angle_error = np.mean(
        [
            abs(
                angle - 90
            )
            for angle in angles
        ]
    )

    # Quá méo thì loại
    if average_angle_error > 35:
        return None

    # -----------------------------------------------------
    # Ratio similarity
    # -----------------------------------------------------

    target_ratio = 1.586

    ratio_error = abs(
        ratio - target_ratio
    )

    ratio_score = 1.0 / (
        1.0 + ratio_error
    )

    # -----------------------------------------------------
    # Area score
    # -----------------------------------------------------

    area_ratio = (
        area
        / image_area
    )

    # -----------------------------------------------------
    # Angle score
    # -----------------------------------------------------

    angle_score = 1.0 / (
        1.0
        + average_angle_error / 10.0
    )

    # -----------------------------------------------------
    # Final score
    # -----------------------------------------------------

    score = (
        area_ratio
        * rectangularity
        * width_similarity
        * height_similarity
        * ratio_score
        * angle_score
    )

    # Không ưu tiên những contour sát mép ảnh
    border_penalty = 1.0

    margin_x = image_width * 0.01
    margin_y = image_height * 0.01

    for x, y in ordered:

        if (
            x <= margin_x
            or x >= image_width - margin_x
            or y <= margin_y
            or y >= image_height - margin_y
        ):
            border_penalty = 0.7
            break

    score *= border_penalty

    return score


# =========================================================
# FIND BEST QUADRILATERAL
# =========================================================

def find_best_quadrilateral(
    contours,
    image_shape
):

    candidates = []

    for contour in contours:

        perimeter = cv2.arcLength(
            contour,
            True
        )

        if perimeter <= 0:
            continue

        # -------------------------------------------------
        # Approx contour
        # -------------------------------------------------

        epsilon = (
            0.02
            * perimeter
        )

        approx = cv2.approxPolyDP(
            contour,
            epsilon,
            True
        )

        # CCCD phải có 4 góc
        if len(approx) != 4:
            continue

        # Convex
        if not cv2.isContourConvex(
            approx
        ):
            continue

        points = (
            approx.reshape(
                4,
                2
            ).astype(
                np.float32
            )
        )

        score = score_quadrilateral(
            contour,
            points,
            image_shape
        )

        if score is None:
            continue

        candidates.append(
            (
                score,
                points,
                contour
            )
        )

    if not candidates:
        return None

    candidates.sort(
        key=lambda item: item[0],
        reverse=True
    )

    return candidates[0]


# =========================================================
# EDGE DETECTION
# =========================================================

def build_edge_mask(image):

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    gray = cv2.GaussianBlur(
        gray,
        (5, 5),
        0
    )

    edges = cv2.Canny(
        gray,
        40,
        120
    )

    # Nối cạnh bị đứt
    kernel = cv2.getStructuringElement(
        cv2.MORPH_RECT,
        (7, 7)
    )

    edges = cv2.morphologyEx(
        edges,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=2
    )

    return edges


# =========================================================
# FIND DOCUMENT FROM EDGES
# =========================================================

def find_document_by_edges(
    image
):

    edges = build_edge_mask(
        image
    )

    contours, _ = cv2.findContours(
        edges,
        cv2.RETR_LIST,
        cv2.CHAIN_APPROX_SIMPLE
    )

    if not contours:
        return None, edges

    best = find_best_quadrilateral(
        contours,
        image.shape
    )

    if best is None:
        return None, edges

    score, points, contour = best

    print(
        f"Edge quadrilateral score: "
        f"{score:.4f}"
    )

    return points, edges


# =========================================================
# HSV MASK
# =========================================================

def build_hsv_mask(
    image,
    kernel_size=31
):

    hsv = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2HSV
    )

    # Màu xanh / xanh xám của CCCD
    lower = np.array(
        [70, 10, 40],
        dtype=np.uint8
    )

    upper = np.array(
        [155, 255, 255],
        dtype=np.uint8
    )

    mask = cv2.inRange(
        hsv,
        lower,
        upper
    )

    # Nối vùng màu gần nhau
    kernel = cv2.getStructuringElement(
        cv2.MORPH_RECT,
        (kernel_size, kernel_size)
    )

    mask = cv2.morphologyEx(
        mask,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=2
    )

    return mask


# =========================================================
# FIND DOCUMENT FROM HSV
# =========================================================

def find_document_by_hsv(
    image
):

    mask = build_hsv_mask(
        image
    )

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_LIST,
        cv2.CHAIN_APPROX_SIMPLE
    )

    if not contours:
        return None, mask

    best = find_best_quadrilateral(
        contours,
        image.shape
    )

    if best is None:
        return None, mask

    score, points, contour = best

    print(
        f"HSV quadrilateral score: "
        f"{score:.4f}"
    )

    return points, mask


# =========================================================
# COARSE HSV REGION
# =========================================================

def find_coarse_region(
    image
):

    mask = build_hsv_mask(
        image
    )

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    if not contours:
        return None, mask

    image_area = (
        image.shape[0]
        * image.shape[1]
    )

    candidates = []

    for contour in contours:

        area = cv2.contourArea(
            contour
        )

        if area < (
            image_area * 0.015
        ):
            continue

        x, y, w, h = cv2.boundingRect(
            contour
        )

        if w <= 0 or h <= 0:
            continue

        ratio = (
            max(w, h)
            / min(w, h)
        )

        # Vùng nghi là document
        if ratio < 1.1 or ratio > 4.0:
            continue

        candidates.append(
            (
                area,
                (
                    x,
                    y,
                    w,
                    h
                )
            )
        )

    if not candidates:
        return None, mask

    candidates.sort(
        key=lambda item: item[0],
        reverse=True
    )

    return (
        candidates[0][1],
        mask
    )


def find_document_by_coarse_hsv_bbox(
    image
):
    """
    Khôi phục thẻ có một cạnh bị bàn tay che bằng vùng HSV bao ngoài.

    Chỉ nhận bbox nằm trọn trong ảnh và có đúng tỷ lệ CCCD, để không dùng
    nhầm vùng màu lan sang nền.
    """

    bbox, mask = find_coarse_region(
        image
    )

    if bbox is None:
        return None, mask

    x, y, width, height = bbox

    if width <= 0 or height <= 0:
        return None, mask

    ratio = max(width, height) / min(width, height)
    area_ratio = (width * height) / (image.shape[0] * image.shape[1])

    if (
        ratio < DOCUMENT_RATIO_MIN
        or ratio > DOCUMENT_RATIO_MAX
        or area_ratio < 0.12
        or area_ratio > 0.85
    ):
        return None, mask

    border_margin = max(
        3,
        int(min(image.shape[:2]) * 0.01)
    )

    if (
        x <= border_margin
        or y <= border_margin
        or x + width >= image.shape[1] - border_margin
        or y + height >= image.shape[0] - border_margin
    ):
        return None, mask

    print(
        "Using coarse HSV bounding-box fallback."
    )

    return np.array(
        [
            [x, y],
            [x + width - 1, y],
            [x + width - 1, y + height - 1],
            [x, y + height - 1]
        ],
        dtype=np.float32
    ), mask


# =========================================================
# EXPAND ROI
# =========================================================

def expand_roi(
    image,
    bbox,
    expand_x=2.0,
    expand_y=1.6
):

    x, y, w, h = bbox

    cx = (
        x
        + w / 2
    )

    cy = (
        y
        + h / 2
    )

    new_w = (
        w
        * expand_x
    )

    new_h = (
        h
        * expand_y
    )

    x1 = int(
        max(
            0,
            cx
            - new_w / 2
        )
    )

    y1 = int(
        max(
            0,
            cy
            - new_h / 2
        )
    )

    x2 = int(
        min(
            image.shape[1],
            cx
            + new_w / 2
        )
    )

    y2 = int(
        min(
            image.shape[0],
            cy
            + new_h / 2
        )
    )

    return (
        x1,
        y1,
        x2,
        y2
    )


# =========================================================
# FALLBACK: EDGE MIN AREA RECT
# =========================================================

def find_edge_min_area_fallback(
    image
):
    """
    Tìm document bằng cạnh bao ngoài khi HSV chỉ bắt được một phần màu sắc.

    Điều này đặc biệt cần cho giấy phép lái xe hoặc giấy tờ màu vàng/trắng,
    vì mask HSV dành cho CCCD có thể chỉ giữ lại ảnh chân dung màu xanh.
    """

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    edges = cv2.Canny(
        cv2.GaussianBlur(
            gray,
            (5, 5),
            0
        ),
        30,
        100
    )

    edges = cv2.morphologyEx(
        edges,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(
            cv2.MORPH_RECT,
            (3, 3)
        ),
        iterations=2
    )

    contours, _ = cv2.findContours(
        edges,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    image_area = image.shape[0] * image.shape[1]
    candidates = []

    for contour in contours:

        area = cv2.contourArea(
            contour
        )

        area_ratio = area / image_area

        if area_ratio < 0.03 or area_ratio > 0.80:
            continue

        rect = cv2.minAreaRect(
            contour
        )

        width, height = rect[1]

        if width <= 0 or height <= 0:
            continue

        ratio = max(width, height) / min(width, height)

        if (
            ratio < DOCUMENT_RATIO_MIN
            or ratio > DOCUMENT_RATIO_MAX
        ):
            continue

        rectangularity = area / (width * height)

        if rectangularity < 0.40:
            continue

        # Một contour nội dung (ảnh chân dung, khối chữ...) có thể vô tình có
        # đúng tỷ lệ của thẻ. Nếu nó sát mép ảnh nhưng chỉ chiếm một phần nhỏ
        # khung hình thì đó không thể là toàn bộ giấy tờ. Không dùng nó để
        # tránh crop mất phần còn lại của thẻ.
        box_points = cv2.boxPoints(
            rect
        )

        border_margin = max(
            5,
            int(min(image.shape[:2]) * 0.02)
        )

        touches_border = np.any(
            (box_points[:, 0] <= border_margin)
            | (box_points[:, 0] >= image.shape[1] - 1 - border_margin)
            | (box_points[:, 1] <= border_margin)
            | (box_points[:, 1] >= image.shape[0] - 1 - border_margin)
        )

        if touches_border and area_ratio < 0.40:
            continue

        ratio_score = 1.0 / (
            1.0 + abs(ratio - 1.586)
        )

        candidates.append(
            (
                area_ratio * rectangularity * ratio_score,
                rect
            )
        )

    if not candidates:
        return None, edges

    candidates.sort(
        key=lambda item: item[0],
        reverse=True
    )

    _, best_rect = candidates[0]

    print(
        "Using edge minAreaRect fallback."
    )

    return order_points(
        cv2.boxPoints(
            best_rect
        )
    ), edges


# =========================================================
# FALLBACK: DOCUMENT AGAINST UNIFORM BACKGROUND
# =========================================================

def find_document_against_background(
    image
):
    """
    Tách giấy tờ khi nền ảnh gần đồng nhất nhưng cạnh ngoài bị mờ hoặc phản
    sáng. Chỉ là nhánh dự phòng cuối: màu nền được ước lượng từ bốn mép ảnh,
    sau đó tìm vùng có màu khác nền và kiểm tra lại tỷ lệ hình chữ nhật.
    """

    image_height, image_width = image.shape[:2]
    border_size = max(
        4,
        int(min(image_height, image_width) * 0.025)
    )

    lab_image = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2LAB
    ).astype(
        np.float32
    )

    border_pixels = np.concatenate(
        [
            lab_image[:border_size].reshape(-1, 3),
            lab_image[-border_size:].reshape(-1, 3),
            lab_image[:, :border_size].reshape(-1, 3),
            lab_image[:, -border_size:].reshape(-1, 3)
        ]
    )

    background_color = np.median(
        border_pixels,
        axis=0
    )

    border_distances = np.linalg.norm(
        border_pixels - background_color,
        axis=1
    )

    distance_threshold = max(
        45.0,
        float(np.percentile(border_distances, 95)) + 20.0
    )

    distance = np.linalg.norm(
        lab_image - background_color,
        axis=2
    )

    mask = np.where(
        distance > distance_threshold,
        255,
        0
    ).astype(
        np.uint8
    )

    mask = cv2.morphologyEx(
        mask,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(
            cv2.MORPH_ELLIPSE,
            (13, 13)
        ),
        iterations=2
    )

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    image_area = image_height * image_width
    candidates = []

    for contour in contours:

        area = cv2.contourArea(
            contour
        )

        area_ratio = area / image_area

        if area_ratio < 0.12 or area_ratio > 0.90:
            continue

        rect = cv2.minAreaRect(
            contour
        )

        width, height = rect[1]

        if width <= 0 or height <= 0:
            continue

        ratio = max(width, height) / min(width, height)

        if (
            ratio < DOCUMENT_RATIO_MIN
            or ratio > DOCUMENT_RATIO_MAX
        ):
            continue

        rectangularity = area / (width * height)

        if rectangularity < 0.60:
            continue

        ratio_score = 1.0 / (
            1.0 + abs(ratio - 1.586)
        )

        candidates.append(
            (
                area_ratio * rectangularity * ratio_score,
                rect
            )
        )

    if not candidates:
        return None, mask

    candidates.sort(
        key=lambda item: item[0],
        reverse=True
    )

    print(
        "Using uniform-background document fallback."
    )

    return order_points(
        cv2.boxPoints(
            candidates[0][1]
        )
    ), mask


# =========================================================
# FALLBACK: PERSPECTIVE LINES
# =========================================================

def find_document_by_perspective_lines(
    image
):
    """Tìm CCCD bị chụp nghiêng mạnh từ bốn cạnh thẳng dài nhất."""

    image_height, image_width = image.shape[:2]

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    edges = cv2.Canny(
        cv2.GaussianBlur(
            gray,
            (5, 5),
            0
        ),
        30,
        100
    )

    lines = cv2.HoughLinesP(
        edges,
        rho=1,
        theta=np.pi / 360,
        threshold=30,
        minLineLength=int(image_width * 0.20),
        maxLineGap=20
    )

    if lines is None:
        return None, edges

    horizontal_lines = []
    left_lines = []
    right_lines = []

    for x1, y1, x2, y2 in lines.reshape(-1, 4):

        dx = x2 - x1
        dy = y2 - y1
        length = float(np.hypot(dx, dy))

        if length <= 0:
            continue

        angle = np.degrees(
            np.arctan2(dy, dx)
        )

        normalized_angle = (
            (angle + 90) % 180
        ) - 90

        midpoint_x = (x1 + x2) / 2
        midpoint_y = (y1 + y2) / 2

        line = (
            float(x1),
            float(y1),
            float(x2),
            float(y2),
            length,
            midpoint_x,
            midpoint_y
        )

        if abs(normalized_angle) <= 30:
            horizontal_lines.append(line)
        elif abs(normalized_angle) >= 60:
            # Cạnh trái của giấy tờ có thể nằm gần tâm ảnh khi thẻ nhỏ hoặc
            # bị chụp lệch. Phân loại theo nửa ảnh thay vì giả định cạnh trái
            # luôn sát mép; các kiểm tra hình học phía dưới sẽ loại đường nội
            # dung và đường nền không tạo được tứ giác hợp lệ.
            if midpoint_x <= image_width * 0.50:
                left_lines.append(line)
            elif (
                image_width * 0.45
                <= midpoint_x
                <= image_width * 0.85
            ):
                right_lines.append(line)

    top_lines = [
        line
        for line in horizontal_lines
        if line[6] <= image_height * 0.50
    ]

    bottom_lines = [
        line
        for line in horizontal_lines
        if line[6] >= image_height * 0.55
    ]

    if not (
        top_lines
        and bottom_lines
        and left_lines
        and right_lines
    ):
        return None, edges

    top_lines = sorted(
        top_lines,
        key=lambda line: line[4],
        reverse=True
    )[:12]

    bottom_lines = sorted(
        bottom_lines,
        key=lambda line: line[4],
        reverse=True
    )[:12]

    left_lines = sorted(
        left_lines,
        key=lambda line: line[4],
        reverse=True
    )[:8]

    right_lines = sorted(
        right_lines,
        key=lambda line: line[4],
        reverse=True
    )[:8]

    def intersection(first_line, second_line):
        x1, y1, x2, y2 = first_line[:4]
        x3, y3, x4, y4 = second_line[:4]

        denominator = (
            (x1 - x2) * (y3 - y4)
            - (y1 - y2) * (x3 - x4)
        )

        if abs(denominator) < 1e-6:
            return None

        determinant_one = x1 * y2 - y1 * x2
        determinant_two = x3 * y4 - y3 * x4

        return np.array(
            [
                (
                    determinant_one * (x3 - x4)
                    - (x1 - x2) * determinant_two
                ) / denominator,
                (
                    determinant_one * (y3 - y4)
                    - (y1 - y2) * determinant_two
                ) / denominator
            ],
            dtype=np.float32
        )

    candidates = []

    for top_line in top_lines:
        for bottom_line in bottom_lines:
            for left_line in left_lines:
                for right_line in right_lines:
                    top_left = intersection(top_line, left_line)
                    top_right = intersection(top_line, right_line)
                    bottom_right = intersection(bottom_line, right_line)
                    bottom_left = intersection(bottom_line, left_line)

                    if any(
                        point is None
                        for point in [
                            top_left,
                            top_right,
                            bottom_right,
                            bottom_left
                        ]
                    ):
                        continue

                    points = np.array(
                        [
                            top_left,
                            top_right,
                            bottom_right,
                            bottom_left
                        ],
                        dtype=np.float32
                    )

                    if np.any(points[:, 0] < -image_width * 0.05):
                        continue

                    if np.any(points[:, 0] > image_width * 1.05):
                        continue

                    if np.any(points[:, 1] < -image_height * 0.05):
                        continue

                    if np.any(points[:, 1] > image_height * 1.05):
                        continue

                    # Tránh chọn cạnh chéo của laptop/phông nền nằm cao hơn
                    # mép thật của thẻ. Điều kiện này chỉ áp dụng cho fallback
                    # phối cảnh, vốn chỉ chạy khi HSV đã trả về gần toàn ảnh.
                    if top_right[1] < image_height * 0.10:
                        continue

                    top_width = np.linalg.norm(
                        top_right - top_left
                    )

                    bottom_width = np.linalg.norm(
                        bottom_right - bottom_left
                    )

                    left_height = np.linalg.norm(
                        bottom_left - top_left
                    )

                    right_height = np.linalg.norm(
                        bottom_right - top_right
                    )

                    average_width = (top_width + bottom_width) / 2
                    average_height = (left_height + right_height) / 2

                    if average_height <= 0:
                        continue

                    ratio = average_width / average_height

                    if (
                        ratio < DOCUMENT_RATIO_MIN
                        or ratio > DOCUMENT_RATIO_MAX
                    ):
                        continue

                    area_ratio = cv2.contourArea(
                        points.reshape(-1, 1, 2)
                    ) / (image_width * image_height)

                    if area_ratio < 0.15 or area_ratio > 0.75:
                        continue

                    if average_width > image_width * 0.85:
                        continue

                    ratio_score = 1.0 / (
                        1.0 + abs(ratio - 1.586)
                    )

                    line_score = sum(
                        line[4]
                        for line in [
                            top_line,
                            bottom_line,
                            left_line,
                            right_line
                        ]
                    ) / (2 * (image_width + image_height))

                    candidates.append(
                        (
                            area_ratio * ratio_score * line_score,
                            points
                        )
                    )

    if not candidates:
        return None, edges

    candidates.sort(
        key=lambda candidate: candidate[0],
        reverse=True
    )

    print(
        "Using perspective-line document fallback."
    )

    return order_points(
        candidates[0][1]
    ), edges


def find_document_from_three_sides(
    image
):
    """
    Khôi phục cạnh còn thiếu từ ba cạnh thẳng nhìn thấy được.

    Cách này dành cho giấy tờ bị tay che một phần: ghép một cạnh ngang với
    hai cạnh bên, thử các tỷ lệ giấy tờ hợp lệ rồi chấm điểm bằng mức độ cạnh
    thật xuất hiện dọc theo toàn bộ chu vi dự đoán.
    """

    image_height, image_width = image.shape[:2]

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    edges = cv2.Canny(
        cv2.GaussianBlur(
            gray,
            (5, 5),
            0
        ),
        30,
        100
    )

    lines = cv2.HoughLinesP(
        edges,
        rho=1,
        theta=np.pi / 360,
        threshold=max(
            20,
            int(min(image_height, image_width) * 0.06)
        ),
        minLineLength=int(image_width * 0.18),
        maxLineGap=max(
            15,
            int(image_width * 0.04)
        )
    )

    if lines is None:
        return None, edges

    horizontal_lines = []
    vertical_lines = []

    for x1, y1, x2, y2 in lines.reshape(-1, 4):
        dx = x2 - x1
        dy = y2 - y1
        length = float(np.hypot(dx, dy))

        if length <= 0:
            continue

        angle = np.degrees(
            np.arctan2(dy, dx)
        )

        angle = (
            (angle + 90) % 180
        ) - 90

        line = (
            float(x1),
            float(y1),
            float(x2),
            float(y2),
            length,
            float(angle),
            (x1 + x2) / 2,
            (y1 + y2) / 2
        )

        if abs(angle) <= 30:
            horizontal_lines.append(line)
        elif abs(angle) >= 60:
            vertical_lines.append(line)

    horizontal_lines = sorted(
        horizontal_lines,
        key=lambda line: line[4],
        reverse=True
    )[:30]

    vertical_lines = sorted(
        vertical_lines,
        key=lambda line: line[4],
        reverse=True
    )[:20]

    if not horizontal_lines or len(vertical_lines) < 2:
        return None, edges

    distance_to_edge = cv2.distanceTransform(
        255 - edges,
        cv2.DIST_L2,
        3
    )

    def angle_difference(first_angle, second_angle):
        return abs(
            (
                (first_angle - second_angle + 90) % 180
            ) - 90
        )

    def intersection(first_line, second_line):
        x1, y1, x2, y2 = first_line[:4]
        x3, y3, x4, y4 = second_line[:4]

        denominator = (
            (x1 - x2) * (y3 - y4)
            - (y1 - y2) * (x3 - x4)
        )

        if abs(denominator) < 1e-6:
            return None

        determinant_one = x1 * y2 - y1 * x2
        determinant_two = x3 * y4 - y3 * x4

        return np.array(
            [
                (
                    determinant_one * (x3 - x4)
                    - (x1 - x2) * determinant_two
                ) / denominator,
                (
                    determinant_one * (y3 - y4)
                    - (y1 - y2) * determinant_two
                ) / denominator
            ],
            dtype=np.float32
        )

    def downward_direction(line):
        direction = np.array(
            [
                line[2] - line[0],
                line[3] - line[1]
            ],
            dtype=np.float32
        )

        direction /= np.linalg.norm(
            direction
        )

        if direction[1] < 0:
            direction = -direction

        return direction

    def edge_support(first_point, second_point):
        interpolation = np.linspace(
            0,
            1,
            100
        )[:, None]

        sampled_points = (
            first_point
            + (second_point - first_point) * interpolation
        )

        sampled_x = np.clip(
            np.rint(sampled_points[:, 0]).astype(int),
            0,
            image_width - 1
        )

        sampled_y = np.clip(
            np.rint(sampled_points[:, 1]).astype(int),
            0,
            image_height - 1
        )

        return float(np.mean(
            distance_to_edge[sampled_y, sampled_x] <= 3
        ))

    candidate_ratios = [
        1.35,
        1.50,
        1.586,
        1.75,
        2.00,
        2.20
    ]

    candidates = []

    for left_line in vertical_lines:
        for right_line in vertical_lines:
            if left_line[6] >= right_line[6]:
                continue

            if right_line[6] - left_line[6] < image_width * 0.35:
                continue

            if angle_difference(
                left_line[5],
                right_line[5]
            ) > 20:
                continue

            for top_line in horizontal_lines:
                top_left = intersection(
                    top_line,
                    left_line
                )

                top_right = intersection(
                    top_line,
                    right_line
                )

                if top_left is None or top_right is None:
                    continue

                if top_left[0] >= top_right[0]:
                    continue

                if (
                    top_left[1] < -image_height * 0.10
                    or top_right[1] < -image_height * 0.10
                    or top_left[1] > image_height * 0.70
                    or top_right[1] > image_height * 0.70
                ):
                    continue

                top_width = np.linalg.norm(
                    top_right - top_left
                )

                if (
                    top_width < image_width * 0.30
                    or top_width > image_width * 0.95
                ):
                    continue

                top_min_x = min(
                    top_line[0],
                    top_line[2]
                )

                top_max_x = max(
                    top_line[0],
                    top_line[2]
                )

                overlap = max(
                    0,
                    min(top_max_x, top_right[0])
                    - max(top_min_x, top_left[0])
                ) / top_width

                if overlap < 0.20:
                    continue

                left_direction = downward_direction(
                    left_line
                )

                right_direction = downward_direction(
                    right_line
                )

                for ratio in candidate_ratios:
                    predicted_height = top_width / ratio

                    bottom_left = (
                        top_left
                        + left_direction * predicted_height
                    )

                    bottom_right = (
                        top_right
                        + right_direction * predicted_height
                    )

                    points = np.array(
                        [
                            top_left,
                            top_right,
                            bottom_right,
                            bottom_left
                        ],
                        dtype=np.float32
                    )

                    if np.any(points[:, 0] < -image_width * 0.05):
                        continue

                    if np.any(points[:, 0] > image_width * 1.05):
                        continue

                    if np.any(points[:, 1] < -image_height * 0.05):
                        continue

                    if np.any(points[:, 1] > image_height * 1.05):
                        continue

                    area_ratio = cv2.contourArea(
                        points.reshape(-1, 1, 2)
                    ) / (image_width * image_height)

                    if area_ratio < 0.08 or area_ratio > 0.90:
                        continue

                    supports = [
                        edge_support(
                            points[index],
                            points[(index + 1) % 4]
                        )
                        for index in range(4)
                    ]

                    sorted_supports = sorted(
                        supports,
                        reverse=True
                    )

                    if np.mean(sorted_supports[:3]) < 0.55:
                        continue

                    score = (
                        supports[0] * 0.35
                        + supports[1] * 0.20
                        + supports[2] * 0.25
                        + supports[3] * 0.20
                        # Khi nhiều đường chữ bên trong cùng rất rõ, ưu tiên
                        # tứ giác lớn bao toàn giấy tờ thay vì khối nội dung.
                        + area_ratio * 0.50
                        + overlap * 0.08
                    )

                    border_margin = max(
                        2,
                        int(min(image_height, image_width) * 0.01)
                    )

                    if np.any(
                        (points[:, 0] <= border_margin)
                        | (
                            points[:, 0]
                            >= image_width - 1 - border_margin
                        )
                        | (points[:, 1] <= border_margin)
                        | (
                            points[:, 1]
                            >= image_height - 1 - border_margin
                        )
                    ):
                        score *= 0.50

                    candidates.append(
                        (
                            score,
                            points
                        )
                    )

    if not candidates:
        return None, edges

    candidates.sort(
        key=lambda candidate: candidate[0],
        reverse=True
    )

    print(
        "Using three-side document fallback."
    )

    return order_points(
        candidates[0][1]
    ), edges


# =========================================================
# WARM DOCUMENT MASK
# =========================================================

def build_warm_document_mask(
    image
):
    """
    Tạo mask cho giấy tờ vàng/be như giấy phép lái xe.

    Mask này tách riêng với HSV của CCCD để không thay đổi kết quả nhận diện
    thẻ xanh hiện có.
    """

    hsv = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2HSV
    )

    mask = cv2.inRange(
        hsv,
        np.array(
            [10, 10, 80],
            dtype=np.uint8
        ),
        np.array(
            [45, 255, 255],
            dtype=np.uint8
        )
    )

    return cv2.morphologyEx(
        mask,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(
            cv2.MORPH_RECT,
            (17, 17)
        ),
        iterations=2
    )


# =========================================================
# FALLBACK: HSV MIN AREA RECT
# =========================================================

def find_min_area_fallback(
    image,
    mask,
    trim_outliers=True,
    return_score=False
):

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    if not contours:
        if return_score:
            return None, None

        return None

    image_area = (
        image.shape[0]
        * image.shape[1]
    )

    candidates = []

    for contour in contours:

        area = cv2.contourArea(
            contour
        )

        if area < (
            image_area * 0.03
        ):
            continue

        if area > (
            image_area * 0.90
        ):
            continue

        rect = cv2.minAreaRect(
            contour
        )

        width, height = rect[1]

        if (
            width <= 0
            or height <= 0
        ):
            continue

        ratio = (
            max(width, height)
            / min(width, height)
        )

        if (
            ratio < DOCUMENT_RATIO_MIN
            or ratio > DOCUMENT_RATIO_MAX
        ):
            continue

        box_area = (
            width
            * height
        )

        rectangularity = (
            area
            / box_area
        )

        if rectangularity < 0.40:
            continue

        score = (
            area
            * rectangularity
        )

        candidates.append(
            (
                score,
                rect,
                contour
            )
        )

    if not candidates:
        if return_score:
            return None, None

        return None

    candidates.sort(
        key=lambda item: item[0],
        reverse=True
    )

    best_score, best_rect, best_contour = candidates[0]

    box_points = order_points(
        cv2.boxPoints(
            best_rect
        )
    )

    top_left, top_right, _, bottom_left = box_points

    horizontal_axis = top_right - top_left
    vertical_axis = bottom_left - top_left

    horizontal_length = np.linalg.norm(horizontal_axis)
    vertical_length = np.linalg.norm(vertical_axis)

    if (
        horizontal_length <= 0
        or vertical_length <= 0
    ):
        return None

    horizontal_axis /= horizontal_length
    vertical_axis /= vertical_length

    # Mask HSV đôi khi dính một phần bàn tay hoặc nền cùng tông màu với thẻ.
    # Bỏ 4% điểm biên ở mỗi phía để minAreaRect không bị một phần nhô nhỏ kéo
    # lệch cả cạnh của document.
    contour_points = best_contour.reshape(
        -1,
        2
    ).astype(
        np.float32
    )

    relative_points = contour_points - top_left

    horizontal_projection = relative_points @ horizontal_axis
    vertical_projection = relative_points @ vertical_axis

    trim_percentile = 4.0

    left, right = np.percentile(
        horizontal_projection,
        [trim_percentile, 100 - trim_percentile]
    )

    top, bottom = np.percentile(
        vertical_projection,
        [trim_percentile, 100 - trim_percentile]
    )

    trimmed_points = np.array(
        [
            top_left + horizontal_axis * left + vertical_axis * top,
            top_left + horizontal_axis * right + vertical_axis * top,
            top_left + horizontal_axis * right + vertical_axis * bottom,
            top_left + horizontal_axis * left + vertical_axis * bottom
        ],
        dtype=np.float32
    )

    trimmed_width = right - left
    trimmed_height = bottom - top

    # Mask màu vàng/be ở nhánh GPL là toàn bộ giấy tờ, vì vậy không được trim
    # để tránh cắt mất nội dung gần mép. Mask HSV CCCD vẫn giữ cơ chế loại
    # nhiễu để không lấy nhầm tay hoặc nền cùng tông màu.
    if not trim_outliers:
        points = box_points
    # Chỉ áp dụng trimming khi nó thực sự chỉ bỏ phần nhô nhỏ. Với CCCD sát
    # mép ảnh, mask màu có thể thưa ở một đầu thẻ; trim khi đó sẽ cắt mất cả
    # một phần document và phải giữ nguyên minAreaRect.
    elif (
        trimmed_width < horizontal_length * 0.92
        or trimmed_height < vertical_length * 0.92
    ):
        points = box_points
    else:
        points = trimmed_points

    print(
        "Using minAreaRect fallback."
    )

    if return_score:
        return points, best_score

    return points


# =========================================================
# DRAW DETECTION
# =========================================================

def draw_detection(
    image,
    points
):

    preview = image.copy()

    points = order_points(
        points
    )

    for i in range(4):

        p1 = tuple(
            points[i].astype(int)
        )

        p2 = tuple(
            points[
                (i + 1) % 4
            ].astype(int)
        )

        cv2.line(
            preview,
            p1,
            p2,
            (0, 255, 0),
            5
        )

        cv2.circle(
            preview,
            p1,
            12,
            (0, 0, 255),
            -1
        )

        cv2.putText(
            preview,
            f"P{i + 1}",
            (
                p1[0] + 10,
                p1[1] - 10
            ),
            cv2.FONT_HERSHEY_SIMPLEX,
            1,
            (255, 0, 0),
            2
        )

    return preview


# =========================================================
# MAIN
# =========================================================

image = cv2.imread(
    INPUT_IMAGE
)

if image is None:

    raise FileNotFoundError(
        f"Không thể đọc ảnh: {INPUT_IMAGE}"
    )


print(
    f"Input image size: "
    f"{image.shape[1]} x "
    f"{image.shape[0]}"
)


# =========================================================
# RESIZE FOR DETECTION
# =========================================================

max_side = 1200

scale = min(
    max_side
    / max(
        image.shape[:2]
    ),
    1.0
)

detection_image = cv2.resize(
    image,
    None,
    fx=scale,
    fy=scale,
    interpolation=cv2.INTER_AREA
)

print(
    f"Detection image size: "
    f"{detection_image.shape[1]} x "
    f"{detection_image.shape[0]}"
)


# =========================================================
# DETECTION
# =========================================================

points = None
debug_mask = None
crop_padding_ratio = 0.03
warm_points = None
warm_score = None
warm_mask = None
used_cccd_hsv_fallback = False


# =========================================================
# METHOD 1: FULL IMAGE EDGE
# =========================================================

print()
print(
    "Trying full-image edge detection..."
)

points, debug_mask = (
    find_document_by_edges(
        detection_image
    )
)


if points is not None:

    print(
        "Document found using full-image edges."
    )


# =========================================================
# METHOD 2: FULL IMAGE HSV
# =========================================================

if points is None:

    print()
    print(
        "Full-image edge detection failed."
    )

    print(
        "Trying HSV detection..."
    )

    points, debug_mask = (
        find_document_by_hsv(
            detection_image
        )
    )

    if points is not None:

        print(
            "Document found using HSV."
        )


# =========================================================
# METHOD 3: HSV COARSE REGION + EDGE
# =========================================================

if points is None:

    print()
    print(
        "Trying HSV coarse region + edge detection..."
    )

    bbox, hsv_mask = (
        find_coarse_region(
            detection_image
        )
    )

    if bbox is not None:

        print(
            f"Coarse region: "
            f"x={bbox[0]}, "
            f"y={bbox[1]}, "
            f"w={bbox[2]}, "
            f"h={bbox[3]}"
        )

        x1, y1, x2, y2 = expand_roi(
            detection_image,
            bbox,
            expand_x=2.0,
            expand_y=1.6
        )

        print(
            f"Expanded ROI: "
            f"({x1}, {y1}) -> "
            f"({x2}, {y2})"
        )

        roi = detection_image[
            y1:y2,
            x1:x2
        ]

        roi_points, roi_edges = (
            find_document_by_edges(
                roi
            )
        )

        if roi_points is not None:

            roi_points[:, 0] += x1
            roi_points[:, 1] += y1

            points = roi_points

            debug_mask = (
                np.zeros(
                    detection_image.shape[:2],
                    dtype=np.uint8
                )
            )

            debug_mask[
                y1:y2,
                x1:x2
            ] = roi_edges

            print(
                "Document found inside HSV ROI."
            )


# =========================================================
# METHOD 4: EDGE MIN AREA RECT FALLBACK
# =========================================================

if points is None:

    print()
    print(
        "Trying edge minAreaRect fallback..."
    )

    points, debug_mask = find_edge_min_area_fallback(
        detection_image
    )


# =========================================================
# METHOD 5: HSV MIN AREA RECT FALLBACK
# =========================================================

if points is None:

    print()
    print(
        "Trying warm-color document fallback..."
    )

    warm_mask = build_warm_document_mask(
        detection_image
    )

    warm_points, warm_score = find_min_area_fallback(
        detection_image,
        warm_mask,
        trim_outliers=False,
        return_score=True
    )


# =========================================================
# METHOD 6: CCCD HSV MIN AREA RECT FALLBACK
# =========================================================

if points is None:

    print()
    print(
        "Strict quadrilateral detection failed."
    )

    print(
        "Trying minAreaRect fallback..."
    )

    gentle_hsv_mask = build_hsv_mask(
        detection_image,
        kernel_size=5
    )

    gentle_points, gentle_score = find_min_area_fallback(
        detection_image,
        gentle_hsv_mask,
        return_score=True
    ) if np.any(gentle_hsv_mask) else (None, None)

    medium_hsv_mask = build_hsv_mask(
        detection_image,
        kernel_size=15
    )

    medium_points, medium_score = find_min_area_fallback(
        detection_image,
        medium_hsv_mask,
        return_score=True
    ) if np.any(medium_hsv_mask) else (None, None)

    hsv_mask = build_hsv_mask(
        detection_image
    )

    regular_points, regular_score = find_min_area_fallback(
        detection_image,
        hsv_mask,
        return_score=True
    ) if np.any(hsv_mask) else (None, None)

    cccd_candidates = [
        (
            gentle_score,
            gentle_points,
            gentle_hsv_mask,
            "gentle"
        ),
        (
            medium_score,
            medium_points,
            medium_hsv_mask,
            "medium"
        ),
        (
            regular_score,
            regular_points,
            hsv_mask,
            "regular"
        )
    ]

    valid_cccd_candidates = [
        candidate
        for candidate in cccd_candidates
        if candidate[0] is not None
    ]

    if valid_cccd_candidates:
        cccd_score, cccd_points, cccd_mask, cccd_mask_name = max(
            valid_cccd_candidates,
            key=lambda candidate: candidate[0]
        )

        print(
            f"Using {cccd_mask_name} HSV mask fallback."
        )
    else:
        cccd_points = None
        cccd_score = None
        cccd_mask = hsv_mask

    if (
        warm_score is not None
        and (
            cccd_score is None
            or warm_score > cccd_score
        )
    ):
        points = warm_points
        debug_mask = warm_mask
        print(
            "Using warm-color document fallback."
        )
    else:
        points = cccd_points
        debug_mask = cccd_mask
        used_cccd_hsv_fallback = points is not None

    # HSV chủ yếu bắt phần hoa văn màu của CCCD; phần viền nhựa trong có thể
    # không nằm trong mask. Nới rộng vừa đủ để lấy trọn thẻ ở nhánh fallback.
    if points is not None and used_cccd_hsv_fallback:
        # Mask HSV không thấy vùng trong/sáng như ảnh chân dung, phần chữ
        # trắng và viền nhựa. Nới biên khi dùng riêng nhánh màu CCCD để tránh
        # cắt mất các phần đó; nhánh edge và giấy tờ màu vàng không bị đổi.
        crop_padding_ratio = 0.10


# =========================================================
# METHOD 7: OCCLUDED EDGE / PERSPECTIVE FALLBACK
# =========================================================

# Khi không có ứng viên, hoặc fallback trả về khung chạm nhiều mép ảnh, thử
# khôi phục giấy tờ từ các cạnh còn nhìn thấy. Nhờ vậy một detector thất bại
# không ngăn các detector hình học phía sau được chạy.
fallback_is_suspicious = points is None

if points is not None:
    fallback_x, fallback_y, fallback_width, fallback_height = cv2.boundingRect(
        points.astype(np.float32)
    )

    touches_image_border = sum(
        [
            fallback_x <= 1,
            fallback_y <= 1,
            (
                fallback_x + fallback_width
                >= detection_image.shape[1] - 1
            ),
            (
                fallback_y + fallback_height
                >= detection_image.shape[0] - 1
            )
        ]
    )

    fallback_is_suspicious = (
        touches_image_border >= 2
        and (
            fallback_width * fallback_height
            >= detection_image.shape[0]
            * detection_image.shape[1]
            * 0.70
        )
    )


if fallback_is_suspicious:
    occluded_points, occluded_mask = find_document_by_coarse_hsv_bbox(
        detection_image
    )

    if occluded_points is not None:
        points = occluded_points
        debug_mask = occluded_mask
        crop_padding_ratio = 0.03
    else:
        three_side_points, three_side_edges = (
            find_document_from_three_sides(
                detection_image
            )
        )

        perspective_points, perspective_edges = (
            find_document_by_perspective_lines(
                detection_image
            )
        )

        selected_points = None
        selected_mask = None

        if points is None:
            if three_side_points is not None:
                selected_points = three_side_points
                selected_mask = three_side_edges
            elif perspective_points is not None:
                selected_points = perspective_points
                selected_mask = perspective_edges
        elif three_side_points is None:
            selected_points = perspective_points
            selected_mask = perspective_edges
        elif perspective_points is None:
            selected_points = three_side_points
            selected_mask = three_side_edges
        else:
            three_side_area = cv2.contourArea(
                three_side_points.reshape(-1, 1, 2)
            )

            perspective_area = cv2.contourArea(
                perspective_points.reshape(-1, 1, 2)
            )

            if three_side_area > perspective_area * 1.35:
                selected_points = three_side_points
                selected_mask = three_side_edges
            else:
                selected_points = perspective_points
                selected_mask = perspective_edges

        if selected_points is not None:
            points = selected_points
            debug_mask = selected_mask
            crop_padding_ratio = 0.10


# =========================================================
# METHOD 8: UNIFORM BACKGROUND FALLBACK
# =========================================================

if points is None:

    print()
    print(
        "Trying uniform-background document fallback..."
    )

    points, debug_mask = find_document_against_background(
        detection_image
    )


# =========================================================
# COMPLETE FAILURE
# =========================================================

if points is None:

    print(
        "No reliable document quadrilateral found; "
        "using the full image as a safe fallback."
    )

    detection_height, detection_width = detection_image.shape[:2]

    points = np.array(
        [
            [0, 0],
            [detection_width - 1, 0],
            [detection_width - 1, detection_height - 1],
            [0, detection_height - 1]
        ],
        dtype=np.float32
    )

    if debug_mask is None:
        debug_mask = np.zeros(
            detection_image.shape[:2],
            dtype=np.uint8
        )

    # Không nới thêm ở fallback này để đảm bảo không sinh viền đen và không
    # cắt mất phần giấy tờ khi ảnh nguồn đã rất sát đối tượng.
    crop_padding_ratio = 0.0


# =========================================================
# CONVERT COORDINATES
# =========================================================

points_original = (
    points
    / scale
)

points_original = order_points(
    points_original
)


# =========================================================
# PRINT CORNERS
# =========================================================

print()
print(
    "===== DOCUMENT DETECTION ====="
)

for i, point in enumerate(
    points_original
):

    print(
        f"P{i + 1}: "
        f"({point[0]:.1f}, "
        f"{point[1]:.1f})"
    )


# =========================================================
# SAVE PREVIEW
# =========================================================

preview = draw_detection(
    image,
    points_original
)

cv2.imwrite(
    DETECTION_PREVIEW,
    preview
)


# =========================================================
# SAVE MASK
# =========================================================

if debug_mask is not None:

    # debug_mask có thể là kích thước detection image
    # nên không resize lại toàn bộ ảnh nếu chưa cần.
    cv2.imwrite(
        DOCUMENT_MASK,
        debug_mask
    )


# =========================================================
# PERSPECTIVE CROP
# =========================================================

cropped = perspective_crop(
    image,
    points_original,
    padding_ratio=crop_padding_ratio
)


# =========================================================
# SAVE CROPPED IMAGE
# =========================================================

success = cv2.imwrite(
    DOCUMENT_CROP,
    cropped
)

if not success:

    raise RuntimeError(
        "Không thể lưu document_crop.jpg"
    )


# =========================================================
# RESULT
# =========================================================

print()

print(
    f"Detection preview: "
    f"{DETECTION_PREVIEW}"
)

print(
    f"Mask: "
    f"{DOCUMENT_MASK}"
)

print(
    f"Document crop: "
    f"{DOCUMENT_CROP}"
)

print()

print(
    "Crop completed!"
)
