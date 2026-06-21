<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->alias([
            'org' => ResolveOrganization::class,
            'permission' => CheckPermission::class,
            'super_admin' => EnsureSuperAdmin::class,
            'subscription' => EnsureSubscriptionActive::class,
        ]);

        // Bảo mật đa tổ chức: orgId phải được gán TRƯỚC khi route-model-binding resolve,
        // nếu không global scope OwnedByOrganization sẽ không lọc → rò rỉ dữ liệu chéo tổ chức (IDOR).
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveOrganization::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
