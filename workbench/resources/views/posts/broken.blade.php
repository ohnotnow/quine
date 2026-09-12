{{-- A template Bladestan cannot compile: the PHP it becomes has an unclosed brace, the error a Flux component inlined without Blaze produced on a real app. --}}
@php if ($post->title) { @endphp
<h1>{{ $post->title }}</h1>
