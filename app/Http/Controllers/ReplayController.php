<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReplayResource;
use App\Replay;
use App\Services\HotslogsUploader;
use App\Services\ParserService;
use App\Services\ReplayService;
use Illuminate\Http\Request;

class ReplayController extends Controller
{
    // Number of replays per page
    const PAGE_SIZE = 100;

    /**
     * Upload a replay
     *
     * @param Request $request
     * @param ReplayService $replayService
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, ReplayService $replayService)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'Error' => 'no file specified']);
        }

        $result = $replayService->store($request->file('file'), $request->uploadToHotslogs);

        $response = ['success' => true, 'status' => $result->status, 'originalName' => $request->file('file')->getClientOriginalName()];
        if (isset($result->replay)) {
            $response += [
                'filename' => $result->replay->filename,
                'url' => $result->replay->url,
                'id' => $result->replay->id
            ];
        }
        return response()->json($response);
    }

    /**
     * Show replay list
     *
     * @param Request $request
     * @return string
     */
    public function index(Request $request)
    {
        $query = $this->getQuery($request);
        $replays = $query->limit(ReplayController::PAGE_SIZE)->get();
        return ReplayResource::collection($replays);
    }

    /**
     * Show replay list with page metadata
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function paged(Request $request)
    {
        $page = (int)($request->page ?? 1);
        $query = $this->getQuery($request);
        // disabled due to performance issues
        //$total = $query->count();
        //$pageCount = ceil($total / self::PAGE_SIZE);
        $replays = $query->forPage($page, self::PAGE_SIZE)->get();
        $result = ['per_page' => self::PAGE_SIZE, 'page' => $page, /*'page_count' => $pageCount, 'total' => $total, */'replays' => $replays];

        return response()->json($result);
    }

    /**
     * Show replay details
     *
     * @param $replay
     * @return string
     */
    public function show($replay)
    {
        $replay = Replay::on('mysql_slave')->with('game_map', 'bans', 'bans.hero', 'players', 'players.hero', 'players.talents', 'players.score')->findOrFail($replay);
        return new ReplayResource($replay);
    }

    /**
     * Check whether a replay with given fingerprint is already uploaded
     * Compatible with HotsLogs
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkV3(Request $request)
    {
        $replay = Replay::where('fingerprint', $request->fingerprint)->first();
        if ($replay != null && $request->uploadToHotslogs) {
            HotslogsUploader::queueForUpload($replay);
        }
        return response()->json(['exists' => $replay != null]);
    }

    /**
     * Check whether a replay with given fingerprint is already uploaded
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkV2(Request $request)
    {
        $fingerprint = preg_replace('/^(\w+)-(\w+)-(\w{2})(\w{2})-/', '$1-$2-$4$3-', $request->fingerprint); // swap 2 bytes
        $replay = Replay::where('fingerprint', $fingerprint)->first();
        if ($replay != null && $request->uploadToHotslogs) {
            HotslogsUploader::queueForUpload($replay);
        }
        return response()->json(['exists' => $replay != null]);
    }

    /**
     * Check whether a replay with given fingerprint is already uploaded
     * This if old fingerprint version, retained for compatibility
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkV1(Request $request)
    {
        $exists = Replay::where('fingerprint_old', $request->fingerprint)->exists();
        return response()->json(['exists' => $exists]);
    }

    /**
     * Check whether replays with given fingerprints are already uploaded
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function massCheck(Request $request)
    {
        $all = preg_split("/\r\n|\n|\r/", $request->getContent());
        $exists = Replay::select('fingerprint')->whereIn('fingerprint', $all)->get()->map->fingerprint->toArray();
        $absent = array_values(array_diff($all, $exists));

        return response()->json(['exists' => $exists, 'absent' => $absent]);
    }

    /**
     * Get minimum supported buils
     *
     * @return int
     */
    public function minimumBuild()
    {
        return ParserService::MIN_SUPPORTED_BUILD;
    }

    /**
     * Creates a query object for replays based on a request
     *
     * @param Request $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function getQuery(Request $request)
    {
        $query = Replay::on('mysql_slave')->select(\DB::raw('/*+ MAX_EXECUTION_TIME(30000) */ *'))->with('game_map');

        if ($request->start_date) {
            $query->where('game_date', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->where('game_date', '<=', $request->end_date);
        }

//        if ($request->game_map) {
//            // todo fix selecting by map name
//            $query->where('game_map', $request->game_map);
//        }

        if ($request->game_type) {
            $query->where('game_type', $request->game_type);
        }

        if ($request->min_id) {
            $query->where('id', '>=', $request->min_id);
        }

        if ($request->player) {
            $query->whereIn('id', function ($query) use ($request) {
                $query->select('replay_id')->from('players');
                if (strpos($request->player, '#') === false) {
                    $query->where('battletag_name', $request->player);
                } else {
                    $parts = explode('#', $request->player);
                    $query->where('battletag_name', $parts[0])->where('battletag_id', $parts[1]);
                }
            });
        }

//        todo fix selecting by hero name
//        if ($request->hero) {
//            $query->whereIn('id', function ($query) use ($request) {
//                $query->select('replay_id')->from('players')->where('hero_id', $request->hero);
//            });
//        }

        if ($request->with_players) {
            $query->with('bans', 'bans.hero', 'players', 'players.hero', 'players.talents', 'players.score');
        }

        return $query->orderBy('id');
    }
}
