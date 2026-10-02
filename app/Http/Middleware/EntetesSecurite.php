<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité (ancien .htaccess + CSP de l'ancien index.php).
 * Plus besoin de nonce : aucun script en ligne, tout est dans /assets/js.
 */
class EntetesSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);

        $reponse->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
        $reponse->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $reponse->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // Pas de mise en cache des pages : elles contiennent des données personnelles.
        if (! $reponse->headers->has('Cache-Control') || str_contains((string) $reponse->headers->get('Content-Type'), 'text/html')) {
            $reponse->headers->set('Cache-Control', 'no-cache, private');
        }
        if ($request->isSecure()) {
            $reponse->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $reponse;
    }
}
