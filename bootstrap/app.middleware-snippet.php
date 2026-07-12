<?php

// Add this line INSIDE your existing ->withMiddleware(function (Middleware $middleware): void { ... }) block.
// Do not replace your whole bootstrap/app.php file.

$middleware->appendToGroup('web', \App\Http\Middleware\UpdateUserLastSeen::class);
