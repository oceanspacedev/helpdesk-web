<?php

namespace App\Services\Integrations\External;

/**
 * Sumber daya tidak ada di sistem eksternal (HTTP 404), mis. kategori yang
 * sudah tidak aktif atau referensi tiket yang tidak dikenal.
 */
class ExternalNotFoundException extends ExternalSystemException {}
