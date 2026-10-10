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
        return $imageDocument->user_id === $user->id
            && $user->can('documents.edit-structured-ocr-own')
            && in_array($imageDocument->status, [
                ImageDocument::STATUS_COMPLETED,
                ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            ], true);
    }

    public function submitForReview(User $user, ImageDocument $imageDocument): bool
    {
        return $imageDocument->user_id === $user->id
            && $user->can('documents.submit-review')
            && in_array($imageDocument->status, [
                ImageDocument::STATUS_COMPLETED,
                ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            ], true);
    }

    public function review(User $user, ImageDocument $imageDocument): bool
    {
        return $user->can('documents.verify')
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
}
