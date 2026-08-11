<?php

namespace App\Modules\Entitlements\Support;

/**
 * One holding, flattened into the shape the fold consumes.
 *
 * The fold must not know whether a bundle came from a synthesized plan package,
 * a real catalog row, or a purchased pack — that is the whole point of slice 2.
 * If the fold ever needs to ask where a bundle came from, the generic billing
 * rule (CLAUDE.md rule 4) has already been broken.
 */
final class GrantBundle
{
    /**
     * @param  string  $type  package | pack | feature
     * @param  int  $rank  dominance order; meaningful only for `package`
     * @param  int  $quantity  multiplier; meaningful only for `pack`
     * @param  array<string, int|null>  $grants  key => value; null = unlimited
     * @param  array<string, bool>  $flags  boolean features carried by a package
     */
    public function __construct(
        public readonly string $type,
        public readonly int $rank = 0,
        public readonly int $quantity = 1,
        public readonly array $grants = [],
        public readonly array $flags = [],
    ) {}
}
