<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $status }} — GPSwiss.fr</title><meta name="robots" content="noindex, noarchive"></head>
<body><main>
<h1>{{ match ($status) { 403 => 'Accès non autorisé', 404 => 'Page introuvable', 419 => 'Session expirée', 503 => 'Maintenance en cours', default => 'Une erreur est survenue' } }}</h1>
<p>{{ match ($status) { 403 => 'Ce lien est invalide ou a expiré. Contactez-nous si vous avez besoin d’aide.', 404 => 'La page demandée n’est pas disponible.', 419 => 'Rechargez la page et réessayez. Vérifiez l’état de votre commande avant de recommencer un paiement.', 503 => 'La boutique est temporairement indisponible. Merci de réessayer plus tard.', default => 'Merci de réessayer plus tard. Vérifiez l’état de votre commande avant de recommencer un paiement.' } }}</p>
<p><a href="https://gpswiss.fr/">Retour à l’accueil</a></p>
<p><a href="mailto:{{ config('storefront-contact.fr.email') }}">{{ config('storefront-contact.fr.email') }}</a> — {{ config('storefront-contact.fr.phone') }}</p>
</main></body></html>
