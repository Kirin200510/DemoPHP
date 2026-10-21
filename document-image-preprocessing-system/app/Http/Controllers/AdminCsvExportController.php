<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ImageDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCsvExportController extends Controller
{
    // Trạng thái hồ sơ
    private const STATUSES = [
        ImageDocument::STATUS_PROCESSING,
        ImageDocument::STATUS_COMPLETED,
        ImageDocument::STATUS_FAILED,
        ImageDocument::STATUS_PENDING_REVIEW,
        ImageDocument::STATUS_VERIFIED,
        ImageDocument::STATUS_RESUBMISSION_REQUIRED,
    ];

    // Trạng thái OCR được xuất dl
    private const OCR_STATUSES = [
        ImageDocument::STATUS_COMPLETED,
        ImageDocument::STATUS_PENDING_REVIEW,
        ImageDocument::STATUS_VERIFIED,
        ImageDocument::STATUS_RESUBMISSION_REQUIRED,
    ];

    private const MAX_ROWS = 5000;

    public function index(Request $request)
    {
        if (! $request->user()->can('documents.export-management-csv')) {
            abort(403, 'Bạn không có quyền truy cập vào trang này.');
        }
        $types = [];
        // Lấy danh sách các loại hồ sơ từ cơ sở dữ liệu(hồ sơ gốc, bỏ qua các hồ sơ đã được gộp vào hồ sơ khác)
        foreach (ImageDocument::query()->whereNull('merged_into_document_id')->cursor() as $document) {
            $type = $this->documentType($document);
            $types[$type] = $type;
        }
        ksort($types);

        return view('admin.exports.index', [
            'documentTypes' => $types,
            'statuses' => self::STATUSES,
        ]);
    }

    // Xác định loại giấy tờ
    private function documentType(ImageDocument $document): string
    {
        $data = $document->ocr_structured_data;
        $paired = data_get($data, 'pairing.status') === 'matched';
        $type = $paired
            ? data_get($data, 'paired_normalized_ocr.document.document_type')
            : data_get($data, 'document.document_type');

        return is_string($type) && trim($type) !== '' ? trim($type) : 'Unknown';
    }

    // Lấy các trường ocr có giá trị đã chuẩn hóa
    private function normalizedFields(ImageDocument $document): array
    {
        $data = $document->ocr_structured_data;
        $paired = data_get($data, 'pairing.status') === 'matched';
        $fields = $paired
            ? data_get($data, 'paired_normalized_ocr.fields', [])
            : data_get($data, 'fields', []);
        if (! is_array($fields)) {
            return [];
        }
        $result = [];
        foreach ($fields as $name => $field) {
            $value = is_array($field)
                ? data_get($field, 'normalized_value')
                : null;

            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $result[(string) $name] = $value;
        }

        return $result;
    }

    // Chống CSV Injection
    private function safeCell(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = (string) $value;
        $value = str_replace(["\t", "\n", "\r"], ' ', $value);
        // Kiểm tra ký tự ở đầu ô CSV
        if (preg_match('/^\s*[=+\-@＝＋－＠]/u', $value) === 1) {
            $value = ' '.$value;
        }

        return $value;
    }

    // Khung CSV quản lý hồ sơ
    private function managementRows(array $documents): array
    {
        $headers = [
            'document_id',
            'customer_id',
            'document_type',
            'status',
            'is_paired',
            'uploaded_at',
            'reviewed_at',
            'purged_at',
        ];
        $rows = [];
        foreach ($documents as $document) {
            $paired = data_get($document->ocr_structured_data, 'pairing.status') === 'matched';
            $rows[] = [
                $document->id,
                $document->user_id,
                $this->documentType($document),
                $document->status,
                $paired ? 'yes' : 'no',
                $document->created_at?->format('Y-m-d H:i:s'),
                $document->reviewed_at?->format('Y-m-d H:i:s'),
                $document->purged_at?->format('Y-m-d H:i:s'),
            ];
        }

        return [$headers, $rows];
    }

    // Khung CSV dữ liệu OCR
    private function ocrRows(array $documents): array
    {
        $headers = [
            'document_id',
            'customer_id',
            'document_type',
            'status',
            'uploaded_at',
        ];
        $allNames = [];
        $allFields = [];
        foreach ($documents as $document) {
            $fields = $this->normalizedFields($document);
            $allFields[$document->id] = $fields;
            foreach (array_keys($fields) as $name) {
                $allNames[$name] = true;
            }
        }
        $names = array_keys($allNames);
        sort($names);
        foreach ($names as $name) {
            $headers[] = "ocr_{$name}";
        }
        $rows = [];
        foreach ($documents as $document) {
            $fields = $allFields[$document->id];
            $row = [
                $document->id,
                $document->user_id,
                $this->documentType($document),
                $document->status,
                $document->created_at?->format('Y-m-d H:i:s'),
            ];
            foreach ($names as $name) {
                $row[] = $fields[$name] ?? '';
            }
            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    // Xử lý lọc để xuất CSV phù hợp
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        if (! $user->can('documents.export-management-csv')) {
            abort(403, 'Bạn không có quyền xuất dữ liệu CSV.');
        }
        // Kiểm tra đầu vào
        $validated = $request->validate([
            'report_type' => ['required', Rule::in(['management', 'ocr'])],
            'status' => ['required', Rule::in(array_merge(['all'], self::STATUSES))],
            'document_type' => ['required', 'string', 'max:100'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => [
                'required_with:from_date',
                'nullable',
                'date_format:Y-m-d',
                Rule::when($request->filled('from_date'), ['after_or_equal:from_date']),
            ],
        ], [
            'to_date.required_with' => 'Vui lòng nhập Đến ngày khi đã nhập Từ ngày.',
            'to_date.after_or_equal' => 'Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.',
        ]);
        $reportType = $validated['report_type'];
        $status = $validated['status'];
        $documentType = $validated['document_type'];
        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        if ($reportType === 'ocr') {
            abort_unless($user->can('documents.export-ocr-csv'), 403, 'Bạn không có quyền xuất dữ liệu OCR CSV.');

            // Không xuất dữ liệu OCR cho các trạng thái không hợp lệ(Fail,Processing)
            if ($status !== 'all' && ! in_array($status, self::OCR_STATUSES)) {
                abort(422, 'Trạng thái không hợp lệ cho báo cáo OCR.');
            }
        }
        // Tạo truy vấn
        $query = ImageDocument::query()->whereNull('merged_into_document_id');
        // Lọc theo status
        if ($status !== 'all') {
            $query->where('status', $status);
        } elseif ($reportType === 'ocr') {
            $query->whereIn('status', self::OCR_STATUSES);
        }
        // lọc theo date
        if ($fromDate !== null) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate !== null) {
            $query->whereDate('created_at', '<=', $toDate);
        }
        // lọc theo loại hồ sơ
        $documents = [];
        foreach ($query->orderBy('id')->cursor() as $document) {
            if ($documentType !== 'all' && $this->documentType($document) !== $documentType) {
                continue;
            }
            if ($reportType === 'ocr') {
                // Nếu là báo cáo OCR, chỉ lấy các hồ sơ có trạng thái hợp lệ
                // purget_at là null, tức là chưa bị xóa
                if ($document->purged_at !== null) {
                    continue;
                }
                // lây các hồ sơ có dữ liệu OCR đã chuẩn hóa
                if ($this->normalizedFields($document) === []) {
                    continue;
                }
            }
            $documents[] = $document;
            if (count($documents) > self::MAX_ROWS) {
                abort(422, 'Số lượng hồ sơ vượt quá giới hạn xuất dữ liệu CSV. Vui lòng thu hẹp phạm vi lọc.');
            }
        }
        // Tạo dữ liệu csv
        [$headers, $rows] = $reportType === 'management'
            ? $this->managementRows($documents)
            : $this->ocrRows($documents);
        // Tạo audit log
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => $reportType === 'ocr'
                ? 'admin.ocr_csv_exported'
                : 'admin.management_csv_exported',
            'auditable_type' => null,
            'auditable_id' => null,
            'metadata' => [
                'report_type' => $reportType,
                'status' => $status,
                'document_type' => $documentType,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'record_count' => count($rows),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        // Tên file
        $filename = $reportType
            .'-documents-'
            .now()->format('Ymd-His')
            .'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Không thể tạo file CSV.');
            }
            fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($output, $headers, ',', '"', '');
            foreach ($rows as $row) {
                $safeRow = array_map(fn ($value) => $this->safeCell($value), $row);
                fputcsv($output, $safeRow, ',', '"', '');
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
