<?php

namespace App\Http\Controllers;

use App\Battle;
use App\GameClient;
use App\Ranker;
use App\Squad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Log;

class SquadController extends Controller
{

    private $client;

    /**
     * SquadController constructor.
     * @param GameClient $client
     */
    public function __construct(GameClient $client)
    {
        $this->client = $client;
    }

    public function viewSquad(Request $request)
    {
        if ($request->has('squadId')) {
            $squadId = $request->input('squadId');
            $squad = $this->client->guildGetPublic($squadId);
            if ($squad === null) {
                return view('squad_not_found');
            }
            $warRecord = [];
            if (!empty($squad->warHistory)) {
                foreach ($squad->warHistory as $battle) {
                    $row = [];
                    $row['endDate'] = Carbon::createFromTimestampUTC($battle->endDate);
                    $row['result'] = ($battle->score > $battle->opponentScore) ? 'WIN' : (($battle->score < $battle->opponentScore) ? 'LOSS' : 'DRAW');
                    $row['score'] = $battle->score;
                    $row['opponentScore'] = $battle->opponentScore;
                    $row['opponent'] = empty($battle->opponentName) ? '<i>Unknown</i>' : urldecode($battle->opponentName);
                    $row['opponentId'] = $battle->opponentGuildId;
                    $warRecord[$battle->endDate] = $row;
                }
            }
            ksort($warRecord);
            $squadName = urldecode($squad->name);
            $squad = \GuzzleHttp\json_encode($squad, JSON_PRETTY_PRINT);
            return view('squad', compact('squadName', 'squad', 'warRecord'));
        }
        return redirect('/');
    }

    public function ssquadSearch(Request $request)
    {
        if ($request->has('name') && Str::length($request->input('name')) >= 3) {
            $results = $this->client->guildSearchByName($request->input('name'));
            foreach ($results as $result) {
                if (Squad::firstOrNew(['id' => $result->_id])->queueIfNeeded()) {
                    Log::info('Added squad ' . $result->_id . ' to queue from search.');
                }
            }
        }
        return view('ssquadsearch', compact('results'));
    }

    public function squadSearch(Request $request)
    {
        if ($request->has('q')) {
            $searchTerm = $request->input('q');
            $results = Squad::whereDeleted(false)
                ->where('name', 'LIKE', '%' . $searchTerm . '%')
                ->orderByRaw('mu - (' . config('sod.sigma_multiplier') . '*sigma) desc')
                ->simplePaginate(20);
            if (count($results) === 1) {
                return redirect()->route('squadhistory', [$results->first()]);
            }
        }
        return view('squadsearch', compact('results'));
    }

    public function squadHistory($id)
    {
        $squad = Squad::findOrFail($id);

        $offensiveBattles = Battle::whereSquadId($squad->id)->get();
        $defensiveBattles = Battle::whereOpponentId($squad->id)->get();

        $battles = [];
        foreach ($offensiveBattles as $offensiveBattle) {
            $skill_before = Ranker::calculateScore($offensiveBattle->mu_before, $offensiveBattle->sigma_before);
            $opponent_skill_before = Ranker::calculateScore($offensiveBattle->opponent_mu_before, $offensiveBattle->opponent_sigma_before);
            $battles[$offensiveBattle->end_date] = [
                'score' => $offensiveBattle->score,
                'opponent_score' => $offensiveBattle->opponent_score,
                'opponent' => Squad::findOrNew($offensiveBattle->opponent_id),
                'skill_difference' => max(1, $opponent_skill_before) / max(1, $skill_before),
                'skill_change' => $offensiveBattle->skillChange,
            ];
        }
        foreach ($defensiveBattles as $defensiveBattle) {
            $skill_before = Ranker::calculateScore($defensiveBattle->opponent_mu_before, $defensiveBattle->opponent_sigma_before);
            $opponent_skill_before = Ranker::calculateScore($defensiveBattle->mu_before, $defensiveBattle->sigma_before);
            $battles[$defensiveBattle->end_date] = [
                'score' => $defensiveBattle->opponent_score,
                'opponent_score' => $defensiveBattle->score,
                'opponent' => Squad::findOrNew($defensiveBattle->squad_id),
                'skill_difference' => max(1, $opponent_skill_before) / max(1, $skill_before),
                'skill_change' => $defensiveBattle->opponentSkillChange,
            ];
        }

        krsort($battles);

        return view('squad_history', compact(['battles', 'squad']));
    }

    public function squadMembers($id)
    {
        $squad = Squad::findOrFail($id);

        $members = $squad->members()->orderBy('xp', 'desc')->get();

        return view('squad_members', compact(['members', 'squad']));
    }
}
