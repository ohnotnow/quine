<ul>
    @foreach ($post->comments as $comment)
        <li>
            <strong>{{ $comment->author->name }}</strong>
            <span>{{ $comment->author?->name }}</span>
            <p>{{ $comment->excerpt }}</p>
        </li>
    @endforeach
</ul>

<livewire:comment-box />
<livewire:no-such-thing />
