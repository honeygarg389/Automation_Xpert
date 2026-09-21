<?php

namespace App\Modules\Flows\Services;

use InvalidArgumentException;

/**
 * WhatsappFlowJsonCompiler::decompile() met Meta Flow JSON it cannot represent
 * WITHOUT LOSING CONTENT.
 *
 * Distinct from a plain InvalidArgumentException, which stays reserved for a
 * malformed payload (no screens, a screen that isn't an object, an input with
 * no name). This one is deterministic and about the Flow itself — retrying
 * cannot fix it — so callers must classify and store it as such rather than
 * fold it into a generic "could not be read" that tells the user to try again.
 *
 * It extends InvalidArgumentException so any caller still catching that type
 * keeps working; the message is a complete, user-presentable reason.
 */
class UnsupportedMetaFlowShapeException extends InvalidArgumentException {}
