<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

/** Read existing authentication session; never persist, collect garbage, or acquire cache locks. */
class ReadOnlyPreviewSession
{
    public function __construct(private SessionManager $sessions) {}

    public function handle($request, Closure $next)
    {
        // Remember-me restoration migrates/destroys sessions; require an already authenticated session.
        $guard = app('auth')->guard('web');
        $request->cookies->remove($guard->getRecallerName());

        // Composition avoids Laravel's withoutMiddleware(StartSession) excluding subclasses too.
        $reader = new class($this->sessions) extends StartSession
        {
            public function handle($request, Closure $next)
            {
                if (! $this->sessionConfigured()) {
                    return $next($request);
                }

                return $this->handleStatefulRequest($request, $this->getSession($request), $next);
            }

            protected function collectGarbage(Session $session)
            {
                // Inspection must not delete sessions.
            }

            protected function saveSession($request)
            {
                // Discard changes made in memory by the web middleware.
            }
        };

        return $reader->handle($request, $next);
    }
}
