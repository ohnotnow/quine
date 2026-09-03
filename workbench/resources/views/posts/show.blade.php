<x-layouts.app>
    <h1>{{ $post->title }}</h1>
    <p>By {{ $post->author->name }}</p>

    @can('editor')
        <p>editor tools</p>
        <flux:button>Publish</flux:button>
    @endcan

    @include('posts.comments')
</x-layouts.app>
