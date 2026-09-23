<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/** Pending Operations record; this slice intentionally sends no manager notification. */
final class RestaurantFeedbackAlert extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'restaurant_feedback_request_id', 'outlet_id', 'status'];
}
