<?php

declare(strict_types=1);

// Canonical API endpoint:
//   GET /api/v1/organizations?format=json|json_typo3|csv
// Authentication and response behavior is implemented by the existing export script.

require_once __DIR__ . '/../../export/organizations.php';
