<?php

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;

return [
    'includes' => [
        // App\Filament\Resources\Blog\AuthorResource::class,
    ],
    'excludes' => [
        RoleResource::class,
    ],
    'should_convert_count' => true,
    'enable_convert_tooltip' => true,
];
