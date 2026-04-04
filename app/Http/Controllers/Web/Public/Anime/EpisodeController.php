<?php

namespace App\Http\Controllers\Web\Public\Anime;

use App\Http\Controllers\Controller;
use App\Models\AnimeWatchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EpisodeController extends Controller
{
    public function show(string $animeId, string $episodeId, Request $request)
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

        $episode = Cache::remember('episode-'.$provider.'-'.$episodeId, now()->addMinutes(5), function () use ($episodeId, $provider) {
            return $this->normalizeEpisode(
                Http::get(config('app.api_url').'/'.$provider.'/episode/'.$episodeId)->json()
            );
        });

        if (($episode['statusCode'] ?? 404) != 200) {
            abort($episode['statusCode'] ?? 404);
        }

        if ($request->has('server')) {
            $server = $request->get('server');
            $server = Cache::remember('server-'.$provider.'-'.$server, now()->addMinutes(5), function () use ($server, $provider) {
                return Http::get(config('app.api_url').'/'.$provider.'/server/'.$server)->json();
            });

            if (($server['statusCode'] ?? 404) != 200) {
                abort($server['statusCode'] ?? 404);
            }

            $episode['data']['defaultStreamingUrl'] = $server['data']['details']['url'] ?? $server['data']['url'] ?? null;
        }

        if (Auth::check()) {
            $user = Auth::user();

            // Use updateOrCreate to check if the record exists and update it, or create a new one
            $animeWatchHistory = AnimeWatchHistory::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'anime_id' => $animeId,
                    'episode_id' => $episodeId,
                ],
                [
                    'data' => [
                        'animeId' => $animeId,
                        'anime' => $anime,
                        'episodeId' => $episodeId,
                        'episode' => $episode,
                    ],
                    'type' => 'anime',
                ]
            );
            $animeWatchHistory->touch();

            // get all anime watch history
            $watchedEpisodes = AnimeWatchHistory::where('user_id', $user->id)->where('anime_id', $animeId)->pluck('episode_id')->toArray();
        } else {
            $watchedEpisodes = [];
        }

        $data = [
            'animeId' => $animeId,
            'anime' => $anime,
            'episodeId' => $episodeId,
            'episode' => $episode,
            'provider' => $provider,
            'watchedEpisodes' => $watchedEpisodes,
        ];

        return view('public.anime.episode.show', $data);
    }

    private function normalizeEpisode(?array $response): array
    {
        if (! $response || empty($response['data']['details'])) {
            return $response ?? [];
        }

        $details = $response['data']['details'];

        // server: qualityList → qualities
        if (isset($details['server']['qualityList'])) {
            $details['server']['qualities'] = $details['server']['qualityList'];
        }

        // download: qualityList[{title,urlList}] → downloadUrl.formats[{title,qualities[{title,urls}]}]
        if (isset($details['download']['qualityList'])) {
            $qualities = array_map(fn($q) => [
                'title' => $q['title'],
                'urls'  => array_map(fn($u) => ['title' => $u['title'], 'url' => $u['url']], $q['urlList'] ?? []),
            ], $details['download']['qualityList']);
            $details['downloadUrl'] = ['formats' => [['title' => 'Download', 'qualities' => $qualities]]];
        }

        $response['data'] = array_merge($response['data'], $details);

        return $response;
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
