<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
@include('layouts.partials.theme')
{{-- Plain file under public/ (no build step); the mtime query string busts browser caches on deploy. --}}
<link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ filemtime(public_path('css/panel.css')) }}">
@livewireStyles
