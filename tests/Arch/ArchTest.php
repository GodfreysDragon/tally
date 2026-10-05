<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| These run in milliseconds and fail the build when a convention slips,
| so code review can spend its attention on behaviour instead.
|
*/

arch('php preset')->preset()->php();

arch('security preset')->preset()->security();

arch('laravel preset')->preset()->laravel()
    ->ignoring('App\Http\Controllers\Api'); // Uses invokable-style named actions (send, void) by design.

arch('every file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('classes are final unless built for extension')
    ->expect('App')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Http\Controllers\Controller');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('env() is only read inside config files')
    ->expect('env')
    ->not->toBeUsed();

arch('actions expose a single handle() entry point')
    ->expect('App\Actions')
    ->toHaveMethod('handle')
    ->toBeFinal();

arch('the money value object stays immutable')
    ->expect('App\Support\Money')
    ->toBeReadonly()
    ->toBeFinal();

arch('data objects are readonly')
    ->expect('App\Data')
    ->toBeReadonly();

arch('the domain does not depend on the HTTP layer')
    ->expect(['App\Actions', 'App\Support', 'App\Models', 'App\Data', 'App\Enums', 'App\Jobs'])
    ->not->toUse(['App\Http', 'Illuminate\Http\Request']);

arch('controllers do not touch the query builder directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('jobs are queued')
    ->expect('App\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('notifications are queued')
    ->expect('App\Notifications')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');
