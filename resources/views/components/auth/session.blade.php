{{--
    Signed-in identity + logout. POST + CSRF only — never a link.
    Shared by the player header and the home page so logout is always reachable.
--}}
@auth
    <div class="flex items-center gap-3">
        <p class="text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="player-btn player-btn-quiet player-btn-sm">Sign out</button>
        </form>
    </div>
@endauth
