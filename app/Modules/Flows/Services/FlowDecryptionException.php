<?php

namespace App\Modules\Flows\Services;

use RuntimeException;

/** A request that cannot be authenticated/decrypted with its routed key pair. */
class FlowDecryptionException extends RuntimeException {}
