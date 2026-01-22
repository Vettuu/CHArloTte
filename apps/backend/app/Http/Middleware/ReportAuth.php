<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) env('REPORT_USER');
        $pass = (string) env('REPORT_PASS');

        if ($user === '' || $pass === '') {
            return response('Report credentials not configured.', Response::HTTP_FORBIDDEN);
        }

        $providedUser = (string) $request->getUser();
        $providedPass = (string) $request->getPassword();

        if (! hash_equals($user, $providedUser) || ! hash_equals($pass, $providedPass)) {
            return response('Unauthorized.', Response::HTTP_UNAUTHORIZED)
                ->header('WWW-Authenticate', 'Basic realm="Reports"');
        }

        return $next($request);
    }
}
