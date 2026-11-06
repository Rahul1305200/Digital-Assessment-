<?php
declare(strict_types=1);

namespace App\Support;

use ArrayAccess;
use Illuminate\Http\Request;

/**
 * Lets the ported code keep using $S['key'] while Laravel's session does the real work.
 */
final class SessionBag implements ArrayAccess
{
    public function __construct(private Request $request)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->request->session()->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->request->session()->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->request->session()->put($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->request->session()->forget($offset);
    }
}
