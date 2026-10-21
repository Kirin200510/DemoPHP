@extends('layouts.app')

@section('title', 'Kết quả xử lý #'.$document->id)

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div class="flex flex-col gap-2">
            <a href="{{ route('documents.create') }}" class="text-sm text-cyan-300 transition hover:text-cyan-200">← @role('admin') Tra cứu hồ sơ @else Xử lý ảnh khác @endrole</a>
            <h1 class="text-3xl font-bold tracking-tight text-white">Kết quả xử lý #{{ $document->id }}</h1>
            <p class="text-sm text-slate-400">Tạo lúc {{ $document->created_at->format('H:i · d/m/Y') }}</p>
        </div>
        
        <span class="w-fit rounded-full px-3 py-1 text-xs font-semibold {{ in_array($document->status, [\App\Models\ImageDocument::STATUS_COMPLETED, \App\Models\ImageDocument::STATUS_VERIFIED], true) ? 'bg-emerald-400/10 text-emerald-300' : ($document->status === \App\Models\ImageDocument::STATUS_PENDING_REVIEW ? 'bg-amber-400/10 text-amber-300' : 'bg-red-400/10 text-red-300') }}">
            {{ match ($document->status) {
                \App\Models\ImageDocument::STATUS_COMPLETED => 'Đã xử lý',
                \App\Models\ImageDocument::STATUS_PENDING_REVIEW => 'Chờ xác thực',
                \App\Models\ImageDocument::STATUS_VERIFIED => 'Đã xác thực',
                \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED => 'Yêu cầu gửi lại',
                default => 'Xử lý thất bại',
            } }}
        </span>
    </div>
    @can('exportReport', $document)
        <a href="{{ route('documents.report', $document) }}"
            class="mt-3 inline-flex items-center gap-2 mb-5 
                    rounded-xl bg-cyan-400 px-4 py-2
                    text-sm font-semibold text-slate-950
                    transition hover:bg-cyan-300">

                <span aria-hidden="true">&#8595;</span>
                Xuất báo cáo PDF
        </a>
    @endcan
    @if ($document->status === \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED && $document->review_notes)
        <div class="mb-6 rounded-xl border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-sm text-amber-100" role="alert">
            <p class="font-semibold">Lý do yêu cầu gửi lại hồ sơ</p>
            <p class="mt-1 whitespace-pre-line text-amber-100/85">{{ $document->review_notes }}</p>
        </div>
    @endif

    @php
        $documentSides = collect([$document])
            ->merge($document->mergedDocumentSides)
            ->unique('id')
            ->values();
        $hasPairedSides = $documentSides->count() === 2;
    @endphp

    <section class="grid gap-6 {{ $hasPairedSides ? 'xl:grid-cols-2' : ($document->processed_path ? 'lg:grid-cols-2' : '') }}">
        @foreach ($documentSides as $sideDocument)
            @php
                $sideLabel = $sideDocument->is($document) ? 'Mặt trước' : 'Mặt sau';
            @endphp
            <article class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-white">Ảnh gốc · {{ $sideLabel }}</h2>
                        <p class="text-xs text-slate-400">Tệp người dùng tải lên</p>
                    </div>
                    <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-400">Original</span>
                </div>
                <div class="grid min-h-96 place-items-center bg-slate-900/60 p-4">
                    <img src="{{ route('documents.original', $sideDocument) }}" alt="Ảnh giấy tờ gốc {{ $sideLabel }}" class="max-h-[36rem] w-full object-contain">
                </div>
            </article>

            @if ($sideDocument->processed_path)
                <article class="overflow-hidden rounded-2xl border border-cyan-400/25 bg-cyan-400/[0.04]">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-white">Ảnh sau xử lý · {{ $sideLabel }}</h2>
                        <p class="text-xs text-slate-400">Kết quả cuối từ AI engine</p>
                    </div>
                    <span class="rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs text-cyan-300">Final</span>
                </div>
                <div class="grid min-h-96 place-items-center bg-slate-900/60 p-4">
                    <img src="{{ route('documents.processed', $sideDocument) }}" alt="Ảnh giấy tờ sau xử lý {{ $sideLabel }}" class="max-h-[36rem] w-full object-contain">
                </div>
                </article>
            @endif
        @endforeach
    </section>

    @php
        $faceCropData = data_get($document->ocr_structured_data, 'face_crop', []);
        $hasStoredFaceCrop = data_get($faceCropData, 'status') === 'detected'
            && is_string(data_get($faceCropData, 'stored_path'));
    @endphp

    @can('viewFaceCrop', $document)
    @if ($hasStoredFaceCrop)
        <section class="mt-7 overflow-hidden rounded-2xl border border-violet-400/25 bg-violet-400/[0.04]">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h2 class="font-semibold text-white">Khuôn mặt được trích xuất</h2>
                    <p class="mt-1 text-xs text-slate-400">YuNet phát hiện khuôn mặt trên ảnh giấy tờ sau xử lý.</p>
                </div>
                <span class="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs text-violet-200">Face crop</span>
            </div>
            <div class="grid place-items-center bg-slate-900/60 p-5">
                <img src="{{ route('documents.face-crop', $document) }}" alt="Khuôn mặt được trích xuất từ giấy tờ" class="max-h-80 max-w-full rounded-xl object-contain">
            </div>
        </section>
    @endif
    @endcan

    @php
        $pairingData = data_get($document->ocr_structured_data, 'pairing');
        $pairedNormalizedOcr = data_get($document->ocr_structured_data, 'paired_normalized_ocr');
    @endphp

    @if (is_array($pairingData))
        @php
            $pairingStatus = data_get($pairingData, 'status');
            $pairingSectionClass = match ($pairingStatus) {
                'matched' => 'border-emerald-400/30 bg-emerald-400/[0.05]',
                'not_matched' => 'border-red-400/30 bg-red-400/[0.05]',
                default => 'border-amber-400/30 bg-amber-400/[0.05]',
            };
            $pairingBadgeClass = match ($pairingStatus) {
                'matched' => 'bg-emerald-400/10 text-emerald-300',
                'not_matched' => 'bg-red-400/10 text-red-200',
                default => 'bg-amber-400/10 text-amber-200',
            };
        @endphp
        <section class="mt-7 overflow-hidden rounded-2xl border {{ $pairingSectionClass }}">
            <div class="flex flex-col gap-2 border-b border-white/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-semibold text-white">Đối chiếu hai mặt CCCD</h2>
                    <p class="mt-1 text-xs text-slate-400">OCR từng ảnh độc lập, sau đó kiểm tra số định danh giữa mặt trước và mặt sau.</p>
                </div>
                <span class="w-fit rounded-full px-3 py-1 text-xs font-semibold {{ $pairingBadgeClass }}">
                    {{ match ($pairingStatus) { 'matched' => 'Đã khớp 2 mặt', 'not_matched' => 'Không khớp 2 mặt', default => 'Chưa đủ bằng chứng / cần kiểm tra' } }}
                </span>
            </div>

            <div class="p-5">
                @if (data_get($pairingData, 'matching_method'))
                    <p class="mb-3 text-xs text-slate-400">Phương pháp đối chiếu: {{ match (data_get($pairingData, 'matching_method')) { 'barcode' => 'Mã vạch', 'mrz_and_barcode' => 'MRZ và mã vạch', default => 'MRZ' } }}</p>
                @endif
                @if ($pairingStatus === 'matched' && is_array($pairedNormalizedOcr))
                    <p class="mb-4 text-sm text-emerald-100">Dữ liệu mặt trước và mặt sau đã được gộp; giá trị mặt trước được ưu tiên, mặt sau bổ sung các trường còn thiếu.</p>
                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (data_get($pairedNormalizedOcr, 'fields', []) as $fieldName => $fieldData)
                            <div class="rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ data_get($fieldData, 'label', $fieldName) }}</dt>
                                <dd class="mt-1 break-words font-medium text-slate-100">{{ data_get($fieldData, 'normalized_value', data_get($fieldData, 'raw_value', 'Chưa nhận diện được')) }}</dd>
                                <dd class="mt-1 text-[11px] text-slate-500">Nguồn: {{ data_get($fieldData, 'source_side', 'không xác định') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <p class="text-sm {{ $pairingStatus === 'not_matched' ? 'text-red-200' : 'text-amber-100' }}">{{ data_get($pairingData, 'error.message', 'Chưa đủ thông tin để xác nhận hai mặt thuộc cùng giấy tờ.') }}</p>
                    <p class="mt-2 text-sm text-slate-300">Hai ảnh và kết quả OCR riêng vẫn được lưu, chưa gộp hồ sơ. Bạn có thể chọn lại ảnh hoặc yêu cầu nhân viên kiểm tra thủ công.</p>
                    <a href="{{ route('documents.create') }}" class="mt-3 inline-block text-sm text-cyan-300">Chọn lại ảnh</a>
                @endif
                @if (is_array(data_get($pairingData, 'checks')))
                    @php
                        $checkLabels = [
                            'mrz_positions_6_14_matches_front_suffix' => '9 số cuối CCCD với MRZ vị trí 6–14',
                            'mrz_positions_16_27_matches_front_number' => '12 số CCCD với MRZ vị trí 16–27',
                            'barcode_identifier_matches_front_number' => 'Số CCCD với mã vạch mặt sau',
                            'barcode_full_name_matches_front' => 'Họ tên với mã vạch',
                            'barcode_date_of_birth_matches_front' => 'Ngày sinh với mã vạch',
                            'barcode_sex_matches_front' => 'Giới tính với mã vạch',
                            'mrz_full_name_matches_front' => 'Họ tên với MRZ',
                            'mrz_date_of_birth_matches_front' => 'Ngày sinh với MRZ',
                        ];
                    @endphp
                    <ul class="mt-3 space-y-1 text-xs text-slate-300">
                        @foreach (data_get($pairingData, 'checks', []) as $check => $passed)
                            <li>{{ $checkLabels[$check] ?? $check }}: {{ $passed === true ? 'Khớp' : ($passed === false ? 'Không khớp' : 'Chưa đủ dữ liệu') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif

    @php
        $barcodeRecords = data_get($document->ocr_structured_data, 'barcodes.records', []);
        $barcodeWarnings = data_get($document->ocr_structured_data, 'barcodes.warnings', []);
    @endphp
    @if ($barcodeRecords !== [] || $barcodeWarnings !== [])
        <section class="mt-7 rounded-2xl border border-white/10 bg-slate-900/40 p-5">
            <h2 class="font-semibold text-white">Đọc mã vạch local</h2>
            @foreach ($barcodeRecords as $barcodeRecord)
                <p class="mt-2 text-sm text-slate-300">{{ data_get($barcodeRecord, 'format', 'Mã vạch') }}:
                    @if (data_get($barcodeRecord, 'parsed.status') === 'parsed')
                        Đã đọc và phân tích được nội dung định danh. Đây là bằng chứng đối chiếu, không phải xác thực giấy tờ thật.
                    @else
                        Đã đọc mã vạch nhưng chưa hỗ trợ cấu trúc nội dung; chưa dùng làm bằng chứng ghép đôi.
                    @endif
                </p>
            @endforeach
            @if ($barcodeWarnings !== [])
                <p class="mt-2 text-sm text-amber-200">Bộ đọc mã vạch gặp lỗi. Kết quả OCR độc lập vẫn được giữ nguyên.</p>
            @endif
        </section>
    @endif

    @if ($document->ocr_structured_data)
        @php
            $structuredData = $document->ocr_structured_data;
            $pairedStructuredData = data_get($structuredData, 'paired_normalized_ocr');
            $isPairedResult = data_get($structuredData, 'pairing.status') === 'matched'
                && is_array($pairedStructuredData);
            $structuredFields = $isPairedResult
                ? data_get($pairedStructuredData, 'fields', [])
                : data_get($structuredData, 'fields', []);
            $documentType = $isPairedResult
                ? data_get($pairedStructuredData, 'document', data_get($structuredData, 'document'))
                : data_get($structuredData, 'document');
            $verificationData = data_get($structuredData, 'verification', []);
            $unmappedRawLines = $isPairedResult
                ? data_get($pairedStructuredData, 'unmapped_raw_lines', [])
                : data_get($structuredData, 'unmapped_raw_lines', []);
            $allOcrLines = $isPairedResult
                ? data_get($pairedStructuredData, 'all_ocr_lines', [])
                : data_get($document->ocr_raw_data, 'lines', []);

            if ($isPairedResult && $allOcrLines === []) {
                $allOcrLines = $documentSides
                    ->flatMap(static function (\App\Models\ImageDocument $sideDocument) use ($document): array {
                        $sourceSide = $sideDocument->is($document) ? 'front' : 'back';

                        return array_map(
                            static function (array $line) use ($sourceSide): array {
                                $line['source_side'] = $sourceSide;

                                return $line;
                            },
                            data_get($sideDocument->ocr_raw_data, 'lines', []),
                        );
                    })
                    ->values()
                    ->all();
            }
            $documentTypeKey = data_get($documentType, 'document_type');
            $displayProfiles = [
                'citizen_identity_card_new' => [
                    'document_label' => 'CCCD mới',
                    'fields' => [
                        'personal_identification_number' => 'Số định danh cá nhân/Personal identification number',
                        'full_name' => 'Họ, chữ đệm và tên khai sinh/Full name',
                        'date_of_birth' => 'Ngày, tháng, năm sinh/Date of birth',
                        'sex' => 'Giới tính/Sex',
                        'nationality' => 'Quốc tịch/Nationality',
                        'residence' => 'Nơi cư trú/Place of residence',
                        'place_of_birth' => 'Nơi đăng ký khai sinh/Place of birth',
                        'issue_date' => 'Ngày, tháng, năm cấp/Date of issue',
                        'expiry_date' => 'Ngày, tháng, năm hết hạn/Date of expiry',
                    ],
                ],
                'citizen_identity_card_legacy' => [
                    'document_label' => 'CCCD',
                    'fields' => [
                        'cccd_number' => 'Số/No',
                        'full_name' => 'Họ và tên',
                        'date_of_birth' => 'Ngày sinh',
                        'sex' => 'Giới tính',
                        'nationality' => 'Quốc tịch',
                        'place_of_origin' => 'Quê quán',
                        'residence' => 'Nơi thường trú',
                        'personal_identification' => 'Đặc điểm nhân dạng/Personal identification',
                        'date_month_year' => 'Ngày, tháng, năm/Date, month, year',
                    ],
                ],
                'driver_license' => [
                    'document_label' => 'Giấy phép lái xe',
                    'fields' => [
                        'document_number' => 'Số/No',
                        'full_name' => 'Họ và tên',
                        'date_of_birth' => 'Ngày sinh',
                        'nationality' => 'Quốc tịch',
                        'residence' => 'Nơi cư trú',
                        'license_class' => 'Hạng/Class',
                        'expiry_date' => 'Có giá trị đến',
                    ],
                ],
                'vehicle_registration' => [
                    'document_label' => 'Giấy chứng nhận đăng ký xe',
                    'fields' => [
                        'owner_name' => 'Tên chủ xe',
                        'vehicle_address' => 'Địa chỉ',
                        'brand' => 'Nhãn hiệu',
                        'model_code' => 'Số loại',
                        'engine_number' => 'Số máy',
                        'chassis_number' => 'Số khung',
                        'paint_color' => 'Màu sơn',
                        'operating_scope' => 'Hoạt động trong phạm vi (ô tô)',
                        'registration_plate' => 'Biển số xe đăng ký',
                        'seating_capacity' => 'Số chỗ ngồi (ô tô)',
                        'expiry_date' => 'Giá trị đến ngày',
                        'engine_power' => 'Công suất (xe máy)',
                        'vehicle_type' => 'Loại xe (xe máy)',
                        'engine_displacement' => 'Dung tích (xe máy)',
                    ],
                ],
            ];
            $displayProfileKey = $documentTypeKey === 'citizen_identity_card'
                ? 'citizen_identity_card_'.data_get($documentType, 'card_version', 'legacy')
                : $documentTypeKey;
            $displayProfile = $displayProfiles[$displayProfileKey] ?? null;
            $displayFields = data_get($displayProfile, 'fields', []);

            if ($isPairedResult && $documentTypeKey === 'citizen_identity_card') {
                $displayFields = array_merge(
                    $displayProfiles['citizen_identity_card_legacy']['fields'],
                    $displayProfiles['citizen_identity_card_new']['fields'],
                );
            }

            if (
                $documentTypeKey === 'citizen_identity_card'
                && ! array_key_exists('cccd_number', $structuredFields)
                && array_key_exists('document_number', $structuredFields)
            ) {
                $structuredFields['cccd_number'] = $structuredFields['document_number'];
                unset($structuredFields['document_number']);
            }

            $additionalStructuredFields = array_diff_key($structuredFields, $displayFields);
            $verificationLines = data_get($verificationData, 'matched_lines', []);
        @endphp

        <section class="mt-7 overflow-hidden rounded-2xl border border-emerald-400/25 bg-emerald-400/[0.04]">
            <div class="flex flex-col gap-3 border-b border-white/10 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="font-semibold text-white">Dữ liệu OCR đã chuẩn hóa</h2>
                    <p class="mt-1 text-xs text-slate-400">Hiển thị các trường cố định theo loại giấy tờ.</p>
                </div>
                <span class="w-fit rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs font-medium text-emerald-300">
                    {{ count($allOcrLines) }} dòng OCR
                </span>
            </div>

            <div class="p-5">
                <div class="mb-5 rounded-xl border border-amber-400/20 bg-amber-400/[0.06] px-4 py-3 text-sm text-amber-100">
                    Hãy đối chiếu với ảnh gốc trước khi sử dụng: confidence thể hiện mức tự tin của mô hình, không bảo đảm dữ liệu hoàn toàn chính xác.
                </div>

                @if (data_get($documentType, 'normalized_value'))
                    <div class="mb-5 rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                        <span class="block text-xs font-medium uppercase tracking-wide text-slate-500">Loại giấy tờ</span>
                        <strong class="mt-1 block text-white">{{ data_get($displayProfile, 'document_label', data_get($documentType, 'normalized_value')) }}</strong>
                    </div>
                @endif

                @if ($displayFields)
                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($displayFields as $fieldName => $fieldLabel)
                            @php
                                $fieldData = data_get($structuredFields, $fieldName, []);
                            @endphp
                            <div class="rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    {{ $fieldLabel }}
                                </dt>
                                <dd class="mt-1 break-words font-medium text-slate-100">
                                    {{ data_get($fieldData, 'normalized_value', data_get($fieldData, 'raw_value', 'Chưa nhận diện được')) }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    @can('updateStructuredOcr', $document)
                        <form method="POST" action="{{ route('documents.ocr.update', $document) }}" class="mt-5 rounded-xl border border-cyan-400/20 bg-cyan-400/[0.04] p-4">
                            @csrf
                            @method('PUT')
                            <h3 class="text-sm font-semibold text-white">Cập nhật dữ liệu chuẩn hóa</h3>
                            <p class="mt-1 text-xs text-slate-400">Chỉ thay đổi giá trị chuẩn hóa; dữ liệu OCR thô vẫn được bảo toàn.</p>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($displayFields as $fieldName => $fieldLabel)
                                    @php
                                        $editableFieldData = data_get($structuredFields, $fieldName, []);
                                    @endphp
                                    <label class="flex flex-col gap-1 text-xs text-slate-400">
                                        {{ $fieldLabel }}
                                        <input name="fields[{{ $fieldName }}]" value="{{ old('fields.'.$fieldName, data_get($editableFieldData, 'normalized_value', data_get($editableFieldData, 'raw_value', ''))) }}" class="h-10 rounded-lg border border-white/10 bg-slate-950/70 px-3 text-sm text-white outline-none ring-cyan-400 focus:ring-2">
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="mt-4 h-10 rounded-lg bg-cyan-400 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-300">Lưu dữ liệu chuẩn hóa</button>
                        </form>
                    @endcan

                    @can('submitForReview', $document)
                        <form method="POST" action="{{ route('documents.submit-review', $document) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="h-10 rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 text-sm font-semibold text-emerald-200 hover:bg-emerald-400/20">{{ is_array($pairingData) && data_get($pairingData, 'status') !== 'matched' ? 'Yêu cầu nhân viên kiểm tra thủ công' : 'Gửi hồ sơ cho nhân viên xác thực' }}</button>
                        </form>
                    @endcan

                @else
                    <p class="text-sm text-slate-400">Chưa xác định được loại giấy tờ để áp dụng bộ trường cố định.</p>
                @endif

                @can('review', $document)
                    @if ($document->status === \App\Models\ImageDocument::STATUS_PENDING_REVIEW)
                        <div id="review-actions" class="mt-6 scroll-mt-6 space-y-4">
                            <section class="rounded-2xl border border-emerald-400/30 bg-emerald-400/[0.06] p-5">
                                <span class="text-xs font-semibold uppercase tracking-wider text-emerald-300">Tác vụ xác thực</span>
                                <h3 class="mt-1 text-lg font-semibold text-white">Thông tin chính xác?</h3>
                                <p class="mt-1 text-sm text-slate-300">Đối chiếu ảnh và dữ liệu OCR, sau đó xác nhận hồ sơ.</p>
                                <form method="POST" action="{{ route('documents.verify', $document) }}" class="mt-4">
                                    @csrf
                                    <button type="submit" class="inline-flex h-10 items-center justify-center rounded-lg bg-emerald-600 px-4 text-sm font-bold text-white shadow-lg shadow-emerald-950/20 transition hover:bg-emerald-500">✓ Xác thực hồ sơ</button>
                                </form>
                            </section>

                            <section class="rounded-2xl border border-amber-400/30 bg-amber-400/[0.06] p-5">
                                <span class="text-xs font-semibold uppercase tracking-wider text-amber-300">Tác vụ gửi lại</span>
                                <h3 class="mt-1 text-lg font-semibold text-white">Thông tin chưa chính xác?</h3>
                                <p class="mt-1 text-sm text-slate-300">Nhập lý do cụ thể để khách hàng bổ sung hoặc chụp lại hồ sơ.</p>
                                <form method="POST" action="{{ route('documents.request-resubmission', $document) }}" class="mt-4">
                                    @csrf
                                    <label for="resubmission-reason" class="sr-only">Lý do yêu cầu gửi lại</label>
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <input id="resubmission-reason" name="reason" required maxlength="2000" placeholder="Ví dụ: Ảnh mờ, thông tin chưa khớp..." class="min-w-0 flex-1 rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-sm text-white outline-none ring-amber-400 focus:ring-2">
                                        <button type="submit" class="inline-flex h-10 items-center justify-center rounded-lg bg-amber-600 px-4 text-sm font-bold text-white transition hover:bg-amber-500">Yêu cầu gửi lại</button>
                                    </div>
                                </form>
                            </section>
                        </div>
                    @endif
                @endcan

                @if ($additionalStructuredFields || $verificationLines || $unmappedRawLines)
                    <div class="mt-5 border-t border-white/10 pt-5">
                        <h3 class="text-sm font-semibold text-white">Nội dung OCR chưa gán trường</h3>
                        <p class="mt-1 text-xs text-slate-400">Các thông tin ngoài bộ trường cố định được giữ lại tại đây.</p>
                        <ol class="mt-3 flex flex-col gap-2">
                            @foreach ($additionalStructuredFields as $fieldName => $fieldData)
                                <li class="rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ str($fieldName)->replace('_', ' ')->title() }}</p>
                                    <p class="mt-1 break-words text-sm text-slate-100">{{ data_get($fieldData, 'normalized_value', data_get($fieldData, 'raw_value', '—')) }}</p>
                                </li>
                            @endforeach
                            @foreach ($verificationLines as $line)
                                <li class="flex gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                                    <span class="shrink-0 font-mono text-xs text-slate-500">#{{ data_get($line, 'index', '—') }}</span>
                                    <p class="break-words text-sm text-slate-100">{{ data_get($line, 'text', '—') }}</p>
                                </li>
                            @endforeach
                            @foreach ($unmappedRawLines as $line)
                                <li class="flex gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                                    <span class="shrink-0 font-mono text-xs text-slate-500">#{{ data_get($line, 'index', '—') }}</span>
                                    <div class="min-w-0">
                                        <p class="break-words text-sm text-slate-100">{{ data_get($line, 'text', '—') }}</p>
                                        @if (data_get($line, 'source_side'))
                                            <p class="mt-1 text-xs text-slate-500">Nguồn: {{ data_get($line, 'source_side') === 'back' ? 'Mặt sau' : 'Mặt trước' }}</p>
                                        @endif
                                        @if (is_numeric(data_get($line, 'confidence')))
                                            <p class="mt-1 text-xs text-slate-500">Confidence: {{ number_format((float) data_get($line, 'confidence') * 100, 1) }}%</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </div>
        </section>

        @if ($allOcrLines)
            <section class="mt-7 overflow-hidden rounded-2xl border border-cyan-400/25 bg-cyan-400/[0.04]">
                <div class="border-b border-white/10 px-5 py-4">
                    <h2 class="font-semibold text-white">Toàn bộ nội dung OCR đọc được</h2>
                    <p class="mt-1 text-xs text-slate-400">Danh sách đầy đủ theo thứ tự mô hình đọc từ ảnh. Phần này bảo toàn cả các dòng đã chuẩn hóa lẫn chưa gán trường.</p>
                </div>
                <div class="max-h-[36rem] overflow-auto">
                    <table class="min-w-full divide-y divide-white/10 text-left text-sm">
                        <thead class="sticky top-0 bg-slate-950/95 text-xs uppercase tracking-wide text-slate-500 backdrop-blur">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Dòng</th>
                                @if ($isPairedResult)
                                    <th scope="col" class="px-5 py-3 font-medium">Nguồn</th>
                                @endif
                                <th scope="col" class="px-5 py-3 font-medium">Nội dung đọc được</th>
                                <th scope="col" class="px-5 py-3 font-medium">Confidence</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($allOcrLines as $line)
                                <tr class="align-top">
                                    <td class="px-5 py-3 font-mono text-xs text-slate-500">#{{ data_get($line, 'index', '—') }}</td>
                                    @if ($isPairedResult)
                                        <td class="px-5 py-3 text-xs text-slate-400">{{ data_get($line, 'source_side') === 'back' ? 'Mặt sau' : 'Mặt trước' }}</td>
                                    @endif
                                    <td class="max-w-4xl break-words px-5 py-3 text-slate-100">{{ data_get($line, 'text', '—') }}</td>
                                    <td class="whitespace-nowrap px-5 py-3 text-slate-400">
                                        {{ is_numeric(data_get($line, 'confidence')) ? number_format((float) data_get($line, 'confidence') * 100, 1).'%' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @role('admin')
            <section class="mt-7 grid gap-4 lg:grid-cols-2">
                <details class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                    <summary class="cursor-pointer px-5 py-4 font-semibold text-white">Xem JSON chuẩn hóa</summary>
                    <pre class="max-h-[32rem] overflow-auto border-t border-white/10 bg-slate-950 p-5 text-xs leading-6 text-emerald-200">{{ json_encode($document->ocr_structured_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>

                @if ($document->ocr_raw_data)
                    <details class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                        <summary class="cursor-pointer px-5 py-4 font-semibold text-white">Xem JSON OCR thô</summary>
                        <pre class="max-h-[32rem] overflow-auto border-t border-white/10 bg-slate-950 p-5 text-xs leading-6 text-cyan-200">{{ json_encode($document->ocr_raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </section>
        @endrole
    @endif

    <div class="mt-7 flex flex-wrap gap-3">
        @role('admin')
            <a href="{{ route('documents.create') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/15 bg-white/5 px-5 text-sm font-semibold text-white transition hover:bg-white/10">
                Về tra cứu hồ sơ
            </a>
        @else
            <a href="{{ route('documents.create') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/15 bg-white/5 px-5 text-sm font-semibold text-white transition hover:bg-white/10">
                Chọn ảnh mới
            </a>
        @endrole
    </div>
@endsection
