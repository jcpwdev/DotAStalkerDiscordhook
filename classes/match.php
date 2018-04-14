<?php
/**
 * Created by PhpStorm.
 * User: jcpw
 * Date: 29.03.18
 * Time: 22:10
 */

class match
{

    public $matchId; // integer
    public $win; // Bool
    public $playedAsParty; // Bool
    public $gamemode; //string
    public $duration; //int as seconds
    public $url; //string, opendota URL
    public $players; // array wih "player" objects (those players in the match we care about), key is the steamid
    public $playerIds; // array with steam ids of all our players
    public $winVsMegas; //bool
    public $isRanked; //bool

    // records

    public $topFragger;
    public $feeder;
    public $carry;
    public $healer;
    public $assistKing;

    function __construct($matchId) {

        $this->matchId = $matchId;
        $this->url = 'https://www.opendota.com/matches/' . $matchId;

    }

    public function findTextValue($keyword){

        $firstPlayer = reset($this->players);

        switch ($keyword) {

            case 'gamemode': $string = $this->gamemode;
            break;

            case 'kills': $string = $firstPlayer->kills;
            break;

            case 'hero': $string = $firstPlayer->hero;
            break;

            case 'lastHits': $string = $firstPlayer->lastHits;
            break;

            case 'winText': $string = $this->win ? 'gewonnen' : 'verloren';
            break;

            case 'duration': $string = $this->formTime($this->duration);;
            break;

            case 'playernameAndHero':
                $partyPlayers = array_map(function($data){

                    return $data->username . ' (' . $data->hero .')';

                },$this->players);

                $lastPlayer = array_pop($partyPlayers);

                $string = (count($partyPlayers) > 0  ? implode(', ', $partyPlayers) . ' und ' : '') . $lastPlayer;
            break;

            case 'playername':
                $partyPlayers = array_map(function($data){

                    return $data->username;

                },$this->players);

                $lastPlayer = array_pop($partyPlayers);

                $string = (count($partyPlayers) > 0  ? implode(', ', $partyPlayers). ' und '  : '') . $lastPlayer;
            break;

            case 'playernameHealer':
                $string = $this->players[$this->healer]->username;
                break;

            case 'playernameFeeder':
                $string = $this->players[$this->feeder]->username;
                break;

            case 'playernameCarry':
                $string = $this->players[$this->carry]->username;
                break;

            case 'playernameAssistKing':
                $string = $this->players[$this->assistKing]->username;
                break;

            case 'playernameTopFragger':
                $string = $this->players[$this->topFragger]->username;
                break;

            case 'bestHeroHealing':
                $string = $this->players[$this->healer]->heroHealing;
                break;

            case 'bestNetworth':
                $string = $this->players[$this->carry]->networth;
                break;

            case 'bestDamage':
                $string = $this->players[$this->carry]->heroDamage;
                break;

            case 'bestKills':
                $string = $this->players[$this->topFragger]->kills;
                break;

            case 'bestAssists':
                $string = $this->players[$this->assistKing]->assists;
                break;

            case 'worstDeaths':
                $string = $this->players[$this->feeder]->deaths;
                break;

            default: $string = '??';
        }

        return $string;

    }

    /**
     * @param $seconds integer of seconds
     * @return string of a formatted game time
     *
     * QoL function
     */

    private function formTime($allSeconds){

        $allSeconds = intval($allSeconds);


        $hoursRest = $allSeconds % 3600;
        $hours = ($allSeconds - $hoursRest) / 3600;



        $minutesRest = ($allSeconds % 60);
        $minutes = (($allSeconds - $minutesRest) / 60) - $hours * 60;


        $seconds = $allSeconds - ( $hours * 3600 + $minutes * 60);

        $timestring = ($hours > 0 ? $hours . ':' : '') . $minutes . ':' . $seconds;


        return $timestring;
    }


}