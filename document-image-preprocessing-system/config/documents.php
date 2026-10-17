<?php

return [
    'retention_days' => env('DOCUMENT_RETENTION_DAYS', 30),
    'purge_time' => env('DOCUMENT_RETENTION_PURGE_TIME', '02:00'),
];
