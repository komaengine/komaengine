@include('theme.partials.comments', [
    'series' => $series,
    'chapter' => $chapter ?? null,
    'comments' => $comments,
])
