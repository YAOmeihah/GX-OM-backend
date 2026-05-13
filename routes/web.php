<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 登录路由 - 为API项目提供登录信息页面
Route::get('/login', function () {
    return response()->json([
        'message' => '这是一个API项目，请使用API端点进行认证',
        'api_login_url' => url('/api/login'),
        'api_documentation' => url('/api/documentation'),
        'authentication_method' => 'POST',
        'required_fields' => [
            'login' => '用户名或邮箱',
            'password' => '密码'
        ],
        'response_format' => 'JSON',
        'example_request' => [
            'url' => url('/api/login'),
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => [
                'login' => 'admin@example.com',
                'password' => 'password'
            ]
        ]
    ], 200, [
        'Content-Type' => 'application/json'
    ]);
})->name('login');
