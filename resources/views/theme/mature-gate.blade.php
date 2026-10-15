@extends('theme::layout')

@section('title', 'Age confirmation · '.\App\Support\Koma::siteTitle())
@section('description', 'Confirm your age before viewing mature content.')

@section('content')
    <div class="mature-gate-shell">
        <section class="mature-gate-card" aria-labelledby="mature-gate-title">
            <div class="mature-gate-mark" aria-hidden="true">18+</div>
            <div class="eyebrow">Content preference</div>
            <h1 id="mature-gate-title">Age confirmation required</h1>
            <p>
                This site includes series marked for mature audiences. Confirm that you are at least
                18 years old to include those titles in the catalog and continue to mature series.
            </p>
            <p class="mature-gate-note">Your confirmation lasts only for this browser session.</p>

            <div class="mature-gate-actions">
                <form method="POST" action="{{ route('mature.confirm') }}">
                    @csrf
                    <button type="submit" class="btn block">I am 18 or older</button>
                </form>
                <form method="POST" action="{{ route('mature.decline') }}">
                    @csrf
                    <button type="submit" class="btn ghost block">Keep mature titles hidden</button>
                </form>
            </div>
        </section>
    </div>
@endsection
