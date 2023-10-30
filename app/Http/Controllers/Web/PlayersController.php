<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GameRound;
use App\Models\GameServer;
use App\Models\GlobalStat;
use App\Models\Player;
use App\Models\PlayerParticipation;
use App\Models\PlayersOnline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PlayersController extends Controller
{
    public function index(Request $request)
    {
        $servers = GameServer::where('active', true)
            ->where('invisible', false)
            ->orderBy('server_id', 'asc')
            ->get();
        $serversToShow = $servers->pluck('server_id');

        // Average players online grouped by server, grouped by date
        $averagePlayersOnline = PlayersOnline::select('server_id')
            ->selectRaw('Date(created_at) as date')
            ->selectRaw('(sum(online) / count(id)) as average_online')
            ->whereIn('server_id', $serversToShow)
            ->where('online', '!=', null)
            ->groupBy('server_id', 'date')
            ->orderBy('date')
            ->get();
        $averagePlayersOnline = $averagePlayersOnline->mapToGroups(function ($item, $key) use ($servers) {
            $serverName = $servers->where('server_id', $item['server_id'])->first()->name;
            return [$serverName => [$item['date'], $item['average_online']]];
        })->sortKeys();

        // Data generated by GenerateGlobalPlayStats daily job
        // Because they are slow as heck to generate
        $participations = GlobalStat::where('key', 'unique_player_participations_per_day')->first();
        $participations = $participations ? json_decode($participations->stats, true) : [];
        $playersByCountry = GlobalStat::where('key', 'players_by_country')->first();
        $playersByCountry = $playersByCountry ? json_decode($playersByCountry->stats, true) : [];

        $playerCount = DB::selectOne(DB::raw('SELECT reltuples AS estimate FROM pg_class where relname = \'players\';'));

        $mostPlayersOnline = PlayersOnline::select(
            DB::raw('sum(online) as total_online'),
            'created_at'
        )
            ->groupBy('created_at')
            ->orderBy('total_online', 'desc')
            ->first();
        $mostPlayersOnline = $mostPlayersOnline ? $mostPlayersOnline->total_online : 0;

        $totalAveragePlayersOnline = PlayersOnline::select(
                DB::raw('avg(online) as average_online')
            )
            ->where('online', '!=', null)
            ->first();
        $totalAveragePlayersOnline = $totalAveragePlayersOnline->average_online;

        return Inertia::render('Players/Index', [
            'averagePlayersOnline' => $averagePlayersOnline,
            'participations' => $participations,
            'playersByCountry' => $playersByCountry,
            'totalPlayers' => (int) $playerCount->estimate,
            'mostPlayersOnline' => (int) $mostPlayersOnline,
            'totalAveragePlayersOnline' => (int) $totalAveragePlayersOnline,
        ]);
    }

    public function search(Request $request)
    {
        $players = Player::with([
            'latestConnection' => function ($q) {
                $q->whereRelation('gameRound', 'ended_at', '!=', null)
                    ->whereRelation('gameRound.server', 'invisible', '!=', true);
            },
        ])
            ->filter($request->input('filters', []))
            ->orderBy(
                $request->input('sort_by', 'id'),
                $request->input('descending', 'true') === 'true' ? 'desc' : 'asc'
            )
            ->paginateFilter($request->input('per_page', 15));

        if ($this->wantsInertia($request)) {
            return Inertia::render('Players/Search', [
                'players' => $players,
            ]);
        } else {
            return $players;
        }
    }

    public function show(Request $request, int $player)
    {
        $player = Player::with([
            'latestConnection' => function ($q) {
                $q->select('id', 'player_id', 'created_at', 'round_id')
                    ->whereRelation('gameRound', 'ended_at', '!=', null)
                    ->whereRelation('gameRound.server', 'invisible', '!=', true);
            },
            'firstConnection:id,player_id,created_at',
            'playtime',
        ])
            ->withCount([
                'connections',
                'participations',
                'deaths',
            ])
            ->where('id', $player)
            ->first();

        $favoriteJob = PlayerParticipation::select('job')
            ->selectRaw('count(id) as played_job')
            ->where('job', '!=', null)
            ->where('player_id', $player->id)
            ->groupBy('job')
            ->orderBy('played_job', 'desc')
            ->first();

        $latestRound = null;
        if ($player->latestConnection && $player->latestConnection->round_id) {
            $latestRound = GameRound::with(['latestStationName'])
                ->where('id', $player->latestConnection->round_id)
                ->first();
        }

        return Inertia::render('Players/Show', [
            'player' => $player,
            'latestRound' => $latestRound,
            'favoriteJob' => $favoriteJob ? $favoriteJob->job : null
        ]);
    }
}
