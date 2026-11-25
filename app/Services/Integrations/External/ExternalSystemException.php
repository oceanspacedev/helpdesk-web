<?php

namespace App\Services\Integrations\External;

use RuntimeException;

/**
 * Dasar semua kegagalan saat berbicara dengan sistem eksternal.
 */
abstract class ExternalSystemException extends RuntimeException {}
