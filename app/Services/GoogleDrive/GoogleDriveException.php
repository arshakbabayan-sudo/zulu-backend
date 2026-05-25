<?php

namespace App\Services\GoogleDrive;

use RuntimeException;

/**
 * Wraps every error path inside GoogleDriveService so callers (HTTP
 * controllers) can catch a single, well-named type instead of bare
 * \Google\Service\Exception traces.
 */
class GoogleDriveException extends RuntimeException {}
