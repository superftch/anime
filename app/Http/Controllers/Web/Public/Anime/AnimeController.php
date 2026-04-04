<?php

namespace App\Http\Controllers\Web\Public\Anime;

use App\Http\Controllers\Controller;
use App\Models\AnimeWatchHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnimeController extends Controller
{
    public function index(): View
    {
        $animes = Cache::remember('anime', now()->addMinutes(5), function () {
            return Http::get(config('app.api_url').'/'.config('app.anime_provider').'/anime/')->json();
        });

        $genres = Cache::remember('genres', now()->addMinutes(5), function () {
            return Http::get(config('app.api_url').'/'.config('app.anime_provider').'/genre/')->json();
        });

        $data = [
            'animes' => $animes,
            'genres' => $genres,
        ];

        return view('public.anime.index', $data);
    }

    public function show(string $animeId, Request $request): View
    {
        $provider = in_array($request->query('provider'), ['otakudesu', 'kuramanime'])
            ? $request->query('provider')
            : config('app.anime_provider');

        $anime = Cache::remember('anime-'.$provider.'-'.$animeId, now()->addMinutes(5), function () use ($animeId, $provider) {
            return $this->normalizeAnime(
                Http::get(config('app.api_url').'/'.$provider.'/anime/'.$animeId)->json()
            );
        });

        if (($anime['statusCode'] ?? 404) != 200) {
            abort($anime['statusCode'] ?? 404);
        }

        if (Auth::check()) {
            $user = Auth::user();

            // get all anime watch history
            $watchedEpisodes = AnimeWatchHistory::where('user_id', $user->id)->where('anime_id', $animeId)->pluck('episode_id')->toArray();
        } else {
            $watchedEpisodes = [];
        }

        $data = [
            'animeId' => $animeId,
            'anime' => $anime,
            'provider' => $provider,
            'watchedEpisodes' => $watchedEpisodes,
        ];

        return view('public.anime.show', $data);
    }

    private function normalizeAnime(?array $response): array
    {
        if (! $response || empty($response['data']['details'])) {
            return $response ?? [];
        }

        $details = $response['data']['details'];
        $details['score'] = ['value' => $details['score'] ?? '-'];
        $details['synopsis']['paragraphs'] = $details['synopsis']['paragraphList'] ?? [];
        $details['season'] = $details['season'] ?? ($details['aired'] ?? null);
        $response['data'] = array_merge($response['data'], $details);

        return $response;
    }
}
