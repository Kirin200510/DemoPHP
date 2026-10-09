import unittest

from structured_extraction import extract_fields, infer_document_from_fields, line_value


def ocr_line(index, text, x, y, width=160, height=24):
    """Tạo dòng OCR giả với polygon hình chữ nhật cho unit test layout."""

    return {
        "index": index,
        "text": text,
        "confidence": 0.95,
        "polygon": [
            [x, y],
            [x + width, y],
            [x + width, y + height],
            [x, y + height],
        ],
    }


class ResidenceLayoutExtractionTest(unittest.TestCase):
    def test_joins_multiple_address_lines_in_the_same_layout_column(self):
        lines = [
            ocr_line(0, "Nơi cư trú/Address:", 30, 100, 230),
            ocr_line(1, "12 Đường A", 300, 100),
            ocr_line(2, "Phường B, Quận C", 300, 136, 220),
            ocr_line(3, "Thành phố D", 300, 172),
            ocr_line(4, "TRƯỞNG PHÒNG", 300, 208),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "12 Đường A Phường B, Quận C Thành phố D")
        self.assertEqual(source_indexes, [0, 1, 2, 3])

    def test_stops_before_the_next_document_field(self):
        lines = [
            ocr_line(0, "Nơi thường trú: 12 Đường A", 30, 100, 340),
            ocr_line(1, "Phường B, Quận C", 150, 136, 220),
            ocr_line(2, "Hạng/Class: A1", 30, 172),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "12 Đường A Phường B, Quận C")
        self.assertEqual(source_indexes, [0, 1])

    def test_stops_before_an_issuance_date_near_the_address(self):
        lines = [
            ocr_line(0, "Nơi cư trú/Address:", 30, 100, 230),
            ocr_line(1, "12 Đường A", 300, 100),
            ocr_line(2, "Phường B, Quận C", 300, 136, 220),
            ocr_line(3, "Thành phố D, ngày 08 tháng 11 năm 2023", 300, 172, 420),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "12 Đường A Phường B, Quận C")
        self.assertEqual(source_indexes, [0, 1, 2])

    def test_keeps_address_continuation_when_a_different_field_is_in_the_left_column(self):
        lines = [
            ocr_line(0, "Nơi thường trú:", 300, 100, 220),
            ocr_line(1, "12 Đường A", 560, 100, 220),
            ocr_line(2, "Có giá trị đến:", 40, 140, 220),
            ocr_line(3, "Phường B, Quận C", 560, 140, 260),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "12 Đường A Phường B, Quận C")
        self.assertEqual(source_indexes, [0, 1, 3])

    def test_uses_relative_line_height_instead_of_fixed_pixel_distance(self):
        lines = [
            ocr_line(0, "Nơi cư trú/Address:", 60, 200, 460, 48),
            ocr_line(1, "Khu phố A", 540, 200, 320, 48),
            ocr_line(2, "Phường B, Quận C", 540, 272, 440, 48),
            ocr_line(3, "Thành phố D", 540, 344, 300, 48),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "Khu phố A Phường B, Quận C Thành phố D")
        self.assertEqual(source_indexes, [0, 1, 2, 3])

    def test_does_not_join_a_distant_line_from_another_region(self):
        lines = [
            ocr_line(0, "Nơi cư trú/Address:", 30, 100, 230),
            ocr_line(1, "12 Đường A", 300, 100),
            ocr_line(2, "Phường B", 300, 136),
            ocr_line(3, "Nội dung vùng khác", 900, 172, 220),
        ]

        value, source_indexes = line_value(lines, 0, "residence")

        self.assertEqual(value, "12 Đường A Phường B")
        self.assertEqual(source_indexes, [0, 1, 2])

    def test_extracts_vehicle_registration_fields_and_multiline_address(self):
        lines = [
            ocr_line(0, "Tên chủ xe/Owner's full name: NGUYỄN VĂN A", 30, 40, 540),
            ocr_line(1, "Địa chỉ/Address:", 30, 76, 220),
            ocr_line(2, "12 Đường A", 280, 76, 200),
            ocr_line(3, "Phường B, Quận C", 280, 112, 240),
            ocr_line(4, "Nhãn hiệu/Brand: HONDA", 30, 148, 400),
            ocr_line(5, "Số loại/Model code: MẪU-01", 30, 184, 440),
            ocr_line(6, "Số máy/Engine No: ABC123456", 30, 220, 440),
            ocr_line(7, "Số khung/Chassis No: XYZ123456", 30, 256, 460),
            ocr_line(8, "Biển số đăng ký/N° plate", 30, 292, 360),
            ocr_line(9, "29A-123.45", 30, 328, 240),
        ]

        fields = extract_fields(lines, None)
        document = infer_document_from_fields(fields)

        self.assertEqual(document["document_type"], "vehicle_registration")
        self.assertEqual(fields["owner_name"]["normalized_value"], "Nguyễn Văn A")
        self.assertEqual(
            fields["vehicle_address"]["normalized_value"],
            "12 Đường A Phường B, Quận C",
        )
        self.assertEqual(fields["registration_plate"]["normalized_value"], "29A-123.45")

    def test_keeps_owner_name_below_its_label_and_removes_address_noise(self):
        lines = [
            ocr_line(0, "Tên chủ xe/Owner's full name:", 30, 40, 440),
            ocr_line(1, "NGUYỄN VĂN A", 30, 76, 240),
            ocr_line(2, "Địa chỉ/Address: 12 Đường A);", 30, 112, 520),
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(fields["owner_name"]["normalized_value"], "Nguyễn Văn A")
        self.assertEqual(fields["vehicle_address"]["normalized_value"], "12 Đường A")

    def test_prefers_owner_name_when_ocr_polygons_slightly_overlap(self):
        lines = [
            ocr_line(0, "Tên chủ xe/Owner's full name:", 30, 40, 440, 55),
            ocr_line(1, "NGUYỄN VĂN A", 30, 82, 240),
            ocr_line(2, "Địa chỉ/Address:", 30, 135, 240),
            ocr_line(3, "12 Đường A", 30, 174, 240),
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(fields["owner_name"]["normalized_value"], "Nguyễn Văn A")
        self.assertEqual(fields["owner_name"]["source_line_indexes"], [0, 1])

    def test_uses_only_the_plate_below_the_registration_plate_label(self):
        lines = [
            ocr_line(0, "29A-111.11", 30, 40, 240),
            ocr_line(1, "Biển số đăng ký/N° plate", 30, 100, 360),
            ocr_line(2, "29A-123.45", 30, 148, 240),
        ]

        value, source_indexes = line_value(lines, 1, "registration_plate")

        self.assertEqual(value, "29A-123.45")
        self.assertEqual(source_indexes, [1, 2])

    def test_extracts_plate_when_registration_label_has_small_ocr_error(self):
        lines = [
            ocr_line(0, "Biên số đăng ky/N° plate", 30, 100, 360),
            ocr_line(1, "50AG-017.21(T)", 30, 148, 240),
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(fields["registration_plate"]["normalized_value"], "50AG-017.21")
        self.assertEqual(fields["registration_plate"]["source_line_indexes"], [0, 1])

    def test_recovers_owner_name_from_layout_when_owner_label_is_unreadable(self):
        lines = [
            ocr_line(0, "NGUYỄN VĂN A", 30, 40, 240),
            ocr_line(1, "Địa chỉ/Address: 12 Đường A", 30, 76, 520),
            ocr_line(2, "Nhãn hiệu/Brand: HONDA", 30, 112, 420),
            ocr_line(3, "Số loại/Model code: MẪU-01", 30, 148, 440),
            ocr_line(4, "Số máy/Engine No: ABC123456", 30, 184, 440),
            ocr_line(5, "Số khung/Chassis No: XYZ123456", 30, 220, 460),
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(fields["owner_name"]["normalized_value"], "Nguyễn Văn A")
        self.assertEqual(fields["owner_name"]["source_line_indexes"], [0])

    def test_extracts_vehicle_type_when_short_label_has_small_ocr_error(self):
        lines = [
            ocr_line(0, "Loại xẹ/Type: Mô tô điện", 30, 40, 440),
            ocr_line(1, "Số loại/Model code: MẪU-01", 30, 76, 440),
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(fields["vehicle_type"]["normalized_value"], "Mô tô điện")
        self.assertEqual(fields["model_code"]["normalized_value"], "MẪU-01")


if __name__ == "__main__":
    unittest.main()
