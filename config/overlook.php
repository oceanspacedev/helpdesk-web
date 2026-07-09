<?php

return [
    'includes' => [
        // App\Filament\Resources\Blog\AuthorResource::class,
    ],
    'excludes' => [
        BezhanSalleh\FilamentShield\Resources\RoleResource::class,
    ],
    'should_convert_count' => true,
    'enable_convert_tooltip' => true,
];
