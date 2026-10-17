"""Image evidence for MRZ filler corrections; never rewrite letters by regex alone."""

from pathlib import Path

import cv2
import numpy as np


def is_filler_glyph(mask: np.ndarray) -> bool:
    """Recognize the two sloping arms of '<', unlike the stem of K or curve of C."""

    ys, xs = np.where(mask > 0)
    if not len(xs):
        return False
    glyph = mask[ys.min():ys.max() + 1, xs.min():xs.max() + 1]
    height, width = glyph.shape
    if height < 10 or not 0.3 <= width / height <= 1.5:
        return False
    left_edges = np.array([np.flatnonzero(row)[0] if row.any() else np.nan for row in glyph])
    upper = np.nanmedian(left_edges[int(height * 0.12):int(height * 0.30)])
    middle = np.nanmedian(left_edges[int(height * 0.40):int(height * 0.60)])
    lower = np.nanmedian(left_edges[int(height * 0.70):int(height * 0.88)])
    upper_slope = (upper - middle) / width
    lower_slope = (lower - middle) / width
    return bool(min(upper_slope, lower_slope) >= 0.20 and upper_slope + lower_slope >= 0.65)


def row_filler_evidence(image: np.ndarray, parts: list[dict]) -> list[bool] | None:
    """Return 30 ordered glyph observations only when segmentation is reliable."""

    if not parts or any(not part.get("polygon") for part in parts):
        return None
    polygons = [np.asarray(part["polygon"], dtype=np.float32) for part in parts]
    if any(polygon.shape != (4, 2) or not np.isfinite(polygon).all() for polygon in polygons):
        return None
    polygons.sort(key=lambda polygon: float(polygon[:, 0].mean()))
    source = np.array([polygons[0][0], polygons[-1][1], polygons[-1][2], polygons[0][3]], dtype=np.float32)
    width = int(round(max(np.linalg.norm(source[1] - source[0]), np.linalg.norm(source[2] - source[3]))))
    height = int(round(max(np.linalg.norm(source[3] - source[0]), np.linalg.norm(source[2] - source[1]))))
    if width < 300 or height < 15 or width > image.shape[1] * 2 or height > image.shape[0]:
        return None
    destination = np.array([[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]], dtype=np.float32)
    crop = cv2.warpPerspective(image, cv2.getPerspectiveTransform(source, destination), (width, height), borderValue=(255, 255, 255))
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if crop.ndim == 3 else crop
    _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    _, labels, stats, _ = cv2.connectedComponentsWithStats(binary)
    components = [
        (x, y, w, h, index)
        for index, (x, y, w, h, area) in enumerate(stats[1:], 1)
        if h >= max(10, height * 0.18) and 0.10 <= w / h <= 1.5 and area >= height * height * 0.01
    ]
    if components:
        median_height = float(np.median([component[3] for component in components]))
        components = [component for component in components if component[3] >= median_height * 0.65]
    if not 28 <= len(components) <= 30:
        return None
    median_width = float(np.median([component[2] for component in components]))
    separated = []
    for x, y, w, h, index in components:
        if 1.6 * median_width < w <= 2.6 * median_width:
            mask = labels[y:y + h, x:x + w] == index
            lower, upper = int(w * 0.35), int(w * 0.65)
            column_counts = mask.sum(axis=0)
            split = lower + int(np.argmin(column_counts[lower:upper]))
            if column_counts[split] <= h * 0.15:
                separated.extend(((x, y, split, h, index), (x + split, y, w - split, h, index)))
                continue
        separated.append((x, y, w, h, index))
    components = separated
    if len(components) != 30:
        return None
    components.sort()
    centers = np.array([x + w / 2 for x, _, w, _, _ in components])
    gaps = np.diff(centers)
    pitch = float(np.median(gaps))
    if pitch <= 0 or np.max(np.abs(gaps - pitch)) > pitch * 0.30:
        return None
    return [is_filler_glyph((labels[y:y + h, x:x + w] == index).astype(np.uint8)) for x, y, w, h, index in components]


def load_mrz_image(path: Path | None) -> np.ndarray | None:
    if path is None or not path.is_file():
        return None
    return cv2.imread(str(path))
