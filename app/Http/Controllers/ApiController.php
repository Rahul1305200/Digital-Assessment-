<?php

namespace App\Http\Controllers;

use App\Support\AssessmentApi;
use Illuminate\Http\Request;

/**
 * Single study endpoint (/api.php?action=...), kept identical to the first version
 * so the existing patient and staff screens work without changes.
 */
class ApiController extends Controller
{
    public function __invoke(Request $request)
    {
        return AssessmentApi::run($request);
    }
}
