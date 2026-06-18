<?php

/*
Paste only the alias lines into your existing bootstrap/app.php.
Do not blindly replace your whole bootstrap/app.php if your project already has content.

Find this part:

->withMiddleware(function (Middleware $middleware): void {
    //
})

Then add:
*/

$middleware->alias([
    'active' => \App\Http\Middleware\EnsureUserIsActive::class,
    'role' => \App\Http\Middleware\EnsureUserHasRole::class,
]);
