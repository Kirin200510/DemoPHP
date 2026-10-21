<?php

namespace App\Policies;

use App\Models\ImageDocument;
use App\Models\User;

class ImageDocumentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ImageDocument $imageDocument): bool
    {
        return $this->canStaffView($user, $imageDocument)
            || ($imageDocument->user_id === $user->id && $user->can('documents.view-own'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return ! $user->hasRole('admin') && $user->can('documents.upload');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ImageDocument $imageDocument): bool
    {
        return $this->updateStructuredOcr($user, $imageDocument);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ImageDocument $imageDocument): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ImageDocument $imageDocument): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ImageDocument $imageDocument): bool
    {
        return $user->hasRole('admin');
    }

    public function viewFaceCrop(User $user, ImageDocument $imageDocument): bool
    {
        return $this->isStaff($user)
            && $user->can('documents.view-face-crop')
            && $this->view($user, $imageDocument);
    }

    public function updateStructuredOcr(User $user, ImageDocument $imageDocument): bool
    {
        return $imageDocument->merged_into_document_id === null
            && $imageDocument->user_id === $user->id
            && $user->can('documents.edit-structured-ocr-own')
            && in_array($imageDocument->status, [
                ImageDocument::STATUS_COMPLETED,
                ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            ], true);
    }

    public function submitForReview(User $user, ImageDocument $imageDocument): bool
    {
        return $imageDocument->merged_into_document_id === null
            && $imageDocument->user_id === $user->id
            && $user->can('documents.submit-review')
            && in_array($imageDocument->status, [
                ImageDocument::STATUS_COMPLETED,
                ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            ], true);
    }

    public function review(User $user, ImageDocument $imageDocument): bool
    {
        return $imageDocument->merged_into_document_id === null
            && $user->can('documents.verify')
            && $user->hasAnyRole(['processor', 'reviewer'])
            && $imageDocument->status === ImageDocument::STATUS_PENDING_REVIEW;
    }

    private function canStaffView(User $user, ImageDocument $imageDocument): bool
    {
        if (! $this->isStaff($user) || ! $user->can('documents.view-any')) {
            return false;
        }

        return $user->hasRole('admin')
            || $imageDocument->status === ImageDocument::STATUS_PENDING_REVIEW;
    }

    private function isStaff(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'processor', 'reviewer']);
    }

    public function exportReport(User $user, ImageDocument $imageDocument): bool
    {
        if ($user->hasAnyRole(['admin', 'processor', 'reviewer'])) {
            return false;
        }
        if (! $user->can('documents.view-own')) {
            return false;
        }
        if ($imageDocument->user_id !== $user->id) {
            return false;
        }
        // Không cho phép xuất báo cáo nếu hồ sơ đã được gộp vào hồ sơ khác
        if ($imageDocument->merged_into_document_id !== null) {
            return false;
        }
        if ($imageDocument->purged_at !== null) {
            return false;
        }
        $allowedStatus = [
            ImageDocument::STATUS_COMPLETED,
            ImageDocument::STATUS_VERIFIED,
            ImageDocument::STATUS_PENDING_REVIEW,
            ImageDocument::STATUS_RESUBMISSION_REQUIRED,
        ];
        if (! in_array($imageDocument->status, $allowedStatus, true)) {
            return false;
        }
        // Chọn data tổng hợp nếu khớp 2 mặt
        $isPaired = data_get($imageDocument->ocr_structured_data, 'pairing.status') === 'matched';
        $fields = $isPaired
                    ? data_get($imageDocument->ocr_structured_data, 'paired_normalized_ocr.fields')
                    : data_get($imageDocument->ocr_structured_data, 'fields');
        foreach ($fields as $field) {
            $value = data_get($field, 'normalized_value');
            // Kiểm tra nếu giá trị là một chuỗi không rỗng và không phải là null hoặc false, thì cho phép xuất báo cáo
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
