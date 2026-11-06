<?php
declare(strict_types=1);

namespace App\Support;

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown to end a request with a ready response (replaces the original "echo ...; exit;"),
 * so Laravel can still save the session and run its normal shutdown.
 */
final class ApiStop extends Exception
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('api response');
    }
}
