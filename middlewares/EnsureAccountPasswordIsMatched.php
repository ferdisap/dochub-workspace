<?php

namespace Dochub\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountPasswordIsMatched
{
  /**
   * Handle an incoming request.
   *
   * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
   */
  public function handle(Request $request, Closure $next): Response
  {
    // $password = "password"; // for debug
    if (!$request->password) {
      return abort(401, "Invalid credentials");
    } elseif (!(Hash::check($request->password, $request->user()->password))) {
      return abort(401, "Invalid credentials");
    } else {
      return $next($request);
    }
  }
}
