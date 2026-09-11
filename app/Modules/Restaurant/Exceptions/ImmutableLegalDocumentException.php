<?php

namespace App\Modules\Restaurant\Exceptions;

use RuntimeException;

/**
 * Thrown when a save attempts to change document_type, version or
 * content_body on a legal_document_versions row that is no longer 'draft'.
 *
 * This is the ENFORCEMENT half of the immutability guarantee the migration's
 * docblock describes; without it, "content_body is written once and never
 * edited" was only ever a comment. See
 * LegalDocumentVersion::booted()'s saving() listener — the only place this
 * is thrown.
 */
class ImmutableLegalDocumentException extends RuntimeException {}
