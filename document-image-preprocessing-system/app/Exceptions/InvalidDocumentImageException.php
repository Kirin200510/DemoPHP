<?php

namespace App\Exceptions;

use Exception;

class InvalidDocumentImageException extends Exception
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
