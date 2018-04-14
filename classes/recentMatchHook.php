<?php
/**
 * Created by PhpStorm.
 * User: jcpw
 * Date: 29.03.18
 * Time: 21:23
 */

class recentMatchHook
{

    private $db; // database connection object
    private $heroes; // array with objects per hero
    private $gamemodes; // array with objects per gamemode
    private $items; // array with objects per item
    private $allplayers; // array with all players from local DB
    private $matches; // array containing all match objects (which themselves contain player objects)
    private $messageObject; // array containing sentences (strings)


    /**
     * Setup function
     *
     * initialises the database and gathers player data and static game data
     *
     * */
    function __construct(){

        //todo outsource db credentials
        $this->db = new PDO('mysql:dbname=???????;host=localhost;charset=UTF8','???????', '???????');


        $this->heroes = $this->loadExternalData("heroes.json");
        $this->gamemodes = $this->loadExternalData("gamemodes.json");
        $this->items = $this->loadExternalData("items.json");

        $allPlayersQuery = $this->db->query("SELECT steamid, id FROM lastMatches");
        $this->allplayers = $allPlayersQuery->fetchAll();
    }

    /**
     * Main function
     *
     * does the whole process from checking for new matches, gathering the data and creating a message for discord + sending it
     *
     * */

    public function run(){

        $recentMatchePerPlayer = $this->getRecentMatches();

        $filteredMatchesPerPlayer = $this->filterForNewerMatches($recentMatchePerPlayer);

        if(count($filteredMatchesPerPlayer) > 0){

            $matchesPerParty = $this->detectParties($filteredMatchesPerPlayer);

            $this->getAllMatchData($matchesPerParty);


            $this->refreshMatchIds($matchesPerParty);
            

            $this->messageObject = $this->composeEmbedMessage();

            return $this->messageObject;

        } else {

            echo 'Success - No new matches found for any Player';

            return false;

        }


    }

    /**
     * @param $steamid (int) steamaccount id
     * @return match-id (string / int)
     *
     *
     * Fetches the last match the player of $steamid has played
     */
    private function getMatchId($steamid){

        $url = "https://api.opendota.com/api/players/". $steamid ."/matches?limit=1";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL,$url);

        if(curl_getinfo($ch, CURLINFO_HTTP_CODE) > 399){
            echo 'Error - can\'t connect to api.opendota.com';
            return false;
        }

        $result=curl_exec($ch);
        curl_close($ch);

        return json_decode($result)[0]->match_id;
    }

    /**
     * @return array playerid => matchid
     *
     * loops through all players and finds the matchid of their latest match
     *
     * inbuild sleep to reduce strain on the opendota servers
     */

    private function getRecentMatches(){

        $newMatchIds = [];

        foreach($this->allplayers as $player){

            $newMatchIds[$player['id']] = $this->getMatchId($player['steamid']);

            usleep(330000);

        }

        return $newMatchIds;


    }

    /**
     * @param $matchIdArray
     * @return array
     *
     * reduces the array from getRecentMatches().
     *
     * removes all players that dont have a new match (compared to the matchid that is stored in the local db)
     */

    private function filterForNewerMatches($matchIdArray){

        return array_filter($matchIdArray, function($matchid, $userid){

            $statement = $this->db->query("SELECT id FROM lastMatches WHERE latestMatchId < " . $matchid . ' AND id = '. $userid);

            if($statement) {
                return $statement->rowCount() > 0;
            }

            return false;

        } ,ARRAY_FILTER_USE_BOTH);

    }

    /**
     * @param $matchIdArray
     * @return array
     *
     * restructures the array produced by getRecentMatches() (maybe filtered by filterForNewMatches()) in a way that an array of playerids is assigned to a matchid
     *
     * returns the restructured array
     */

    private function detectParties($matchIdArray){

        $onlyMatchIds = array_count_values($matchIdArray);

        $sortedParties = [];

        foreach($onlyMatchIds as $matchId => $count){

            $sortedParties[$matchId] = array_keys($matchIdArray, $matchId);
        }

        return $sortedParties;
    }

    /**
     * @param $matchesAndParties
     *
     * loops the array produced by detectParties() to gather the matchdata from opendota
     *
     * data per match is saved in a match object. it contains player objects holding the respective playerdata
     *
     * each set of matchdata gets saved in $this->matches as an array value
     *
     */

    private function getAllMatchData($matchesAndParties){

        $allPlayers = [];
        foreach($this->allplayers as $playerdata){

            $allPlayers[intval($playerdata['id'])] = intval($playerdata['steamid']);

        }

        foreach($matchesAndParties as $matchId => $players) {

            $matchJson = $this->getMatchJson($matchId);

            usleep(330000);

            //das hier vll outsourcen
            $playerProfiles = array_filter($matchJson->players, function($player) use ($players, $allPlayers){
                return in_array($player->account_id, array_map(function($dbid) use ($allPlayers){

                    return $allPlayers[$dbid];

                }, $players));
            });

            $this->matches[$matchId] = new match($matchId);

            //todo: outsource everything into match constructor
            $this->matches[$matchId]->playerIds = array_map(function($player){
                return $player->account_id;
            }, $playerProfiles);
            $this->matches[$matchId]->win = (reset($playerProfiles)->win == 1 ? true : false);
            $this->matches[$matchId]->gamemode = $this->getLocalizedName($matchJson->game_mode, $this->gamemodes);
            $this->matches[$matchId]->playedAsParty = count($playerProfiles) > 1;
            $this->matches[$matchId]->duration = $matchJson->duration;
            $this->matches[$matchId]->topFragger = $this->findStandoutPlayerIn($matchJson,'kills');
            $this->matches[$matchId]->feeder = $this->findStandoutPlayerIn($matchJson,'deaths');
            $this->matches[$matchId]->carry = $this->findStandoutPlayerIn($matchJson,'hero_damage');
            $this->matches[$matchId]->healer = $this->findStandoutPlayerIn($matchJson,'hero_healing');
            $this->matches[$matchId]->assistKing = $this->findStandoutPlayerIn($matchJson,'assists');
            $this->matches[$matchId]->winVsMegas = ($matchJson->barracks_status_radiant < 1 && $matchJson->radiant_win === true && $this->matches[$matchId]->win) || ($matchJson->barracks_status_dire < 1 && $matchJson->radiant_win === false && $this->matches[$matchId]->win);
            $this->matches[$matchId]->isRanked = $matchJson->lobby_type == 7;


            foreach ($playerProfiles as $playerProfile){

                $playerId = $playerProfile->account_id;

                $this->matches[$matchId]->players[$playerId] = new player();

                //todo: outsource everything into player constructor
                $this->matches[$matchId]->players[$playerId]->username = $playerProfile->personaname;
                $this->matches[$matchId]->players[$playerId]->hero = $this->getLocalizedName($playerProfile->hero_id, $this->heroes);
                $this->matches[$matchId]->players[$playerId]->kills = $playerProfile->kills;
                $this->matches[$matchId]->players[$playerId]->deaths = $playerProfile->deaths;
                $this->matches[$matchId]->players[$playerId]->assists = $playerProfile->assists;
                $this->matches[$matchId]->players[$playerId]->networth = $playerProfile->total_gold;
                $this->matches[$matchId]->players[$playerId]->heroDamage = $playerProfile->hero_damage;
                $this->matches[$matchId]->players[$playerId]->heroHealing = $playerProfile->hero_healing;
                $this->matches[$matchId]->players[$playerId]->lastHits = $playerProfile->last_hits;
                $this->matches[$matchId]->players[$playerId]->items = [
                    $playerProfile->item_0,
                    $playerProfile->item_1,
                    $playerProfile->item_2,
                    $playerProfile->item_3,
                    $playerProfile->item_4,
                    $playerProfile->item_5,
                    ];

            }

        }

    }


    /**
     * @param array $matchData
     * @param string $criteria
     * @param bool $highestCounts
     *
     * @return int $steamid
     *
     * compares all Players in the $matchData based on the give $criteria (must be present in the matchJson data per player
     * $highest parameter determines if we want to find the highest (eg most kills) or the lowest (eg least deaths)
     *
     */

    private function findStandoutPlayerIn($matchData, $criteria, $highestCounts = true){

         $steamidStandoutPlayer = 0;

         foreach($matchData->players as $player){


             if(
                 !isset($currentStandout)
                 ||
                 ($highestCounts && $player->$criteria > $currentStandout)
                 ||
                 (!$highestCounts && $player->$criteria < $currentStandout)
             ){

                 $currentStandout = $player->$criteria;

                 $steamidStandoutPlayer = $player->account_id;
             }

         }

         return $steamidStandoutPlayer;

    }

    public function getTemplateSentence($specificId = null, $field = null, $party = false, $win = false, $case = 'any'){

        if($specificId){

            $query = "SELECT content from templates WHERE id = " . $specificId;

        } else if($field){

            $partyInt = ($party ? 1 : 0);

            $winInt = ($win ? 1 : 0);

            $query = "SELECT content FROM templates WHERE field = '" . $field . "' AND party in (-1, " . $partyInt . ") AND win in (-1, " . $winInt . ") and tCase = '" . $case . "'";

        } else {

            return;

            //todo: error handling if nothing found
        }

        $getTemplate = $this->db->query($query);

        $result = $getTemplate->fetchAll();

        return $result[array_rand($result)]['content'];

    }

    /**
     * @param $matchIdArray
     * @return PDOStatement Object
     *
     * updates the local db with the given matchIDs for the players
     * important to ensure that on the next call really new matches will be found
     *
     */
    private function refreshMatchIds($matchesAndParties){

        $whenCases = []; // todo: use array_map?
        foreach ($matchesAndParties as $matchId => $players){

            foreach($players as $playerid){
                $whenCases[$playerid] = 'when id = ' . $playerid . ' then ' . $matchId;
            }
        }

        $updateMatchIdsQuery = "UPDATE lastMatches SET latestMatchId = (case " . implode(' ' , $whenCases). " end) WHERE id in (" . implode(',', array_keys($whenCases)) . ")";

        $result = $this->db->query($updateMatchIdsQuery);

        return $result;


    }

    /**
     * @param $jsonFile file-string
     * @return array
     *
     * gathers data from static json-files
     * data is used for metadata like hero names / gamemode names
     *
     * note: this data could by dynamically called from the opendota API. to reduce strain on the opendota services static copies from the json have been made
     *
     */

    private function loadExternalData($jsonFile){
        return json_decode(file_get_contents($jsonFile));
    }

    /**
     * @param $id
     * @param $data
     * @return string
     *
     * searches through a set of data for a specific id and returns the "localized_name" field from it
     */

    private function getLocalizedName($id,$data){

        $dataObject = array_filter($data, function($object, $index) use ($id){
            return $object->id == $id;
        }, ARRAY_FILTER_USE_BOTH);


        return reset($dataObject)->localized_name;

    }

    /**
     * @param $matchId (int)
     * @return array/object
     *
     * retrieves the json data for a single match from opendota
     *
     */

    private function getMatchJson($matchId){

        $url = "https://api.opendota.com/api/matches/". $matchId ;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL,$url);
        $result=curl_exec($ch);
        curl_close($ch);
        return json_decode($result);

    }

    /**
     * @return array (of message strings)
     *
     * grabs $this->matches and writes a short sentence about the outcome of the game into $this->messageObject
     *
     * probably wont be used anymore, because composeEmbedMessage does the same, just cooler
     *
     */
    private function composeStringMessage(){

        $messages = [];

        foreach($this->matches as $machId => $matchData){

            if($matchData->playedAsParty){

                $partyPlayers = array_map(function($data){

                    return $data->username . ' (' . $data->hero .')';

                },$matchData->players);

                $lastPlayer = array_pop($partyPlayers);

                $messages[] = implode(', ', $partyPlayers) . ' ' . '&' . ' ' . $lastPlayer .' haben eine Runde ' . $matchData->gamemode . ' gespielt und ' . ($matchData->win ? 'gewonnen' : 'verloren') . '.';

                // solo match!
            } else {

                $soloPlayer = reset($matchData->players);

                $messages[] = $soloPlayer->username . ' (' . $soloPlayer->hero .') hat eine Runde ' . $matchData->gamemode . ' gespielt und ' . ($matchData->win ? 'gewonnen' : 'verloren') . '.';

            }

        }

        return $messages;
    }

    /**
     * @return array (of arrays containing embed information)
     *
     * generates the neceassry values for discords embed messages like title, url and description
     *
     */

    private function composeEmbedMessage(){

        $embedObjects = [];
        $embedObjectsCount = 0;

        foreach($this->matches as $matchId => $matchData){

            $possibleSettings = []; //reset

            $possibleSettings[] = [

                'titleId' => null,
                'descriptionId' => null,
                'case' => 'any'
            ];

                // Win as Solo/Party against Megas Creeps
                if ($matchData->winVsMegas) {

                    $possibleSettings[] = [
                        'titleId' => 6,
                        'descriptionId' => $matchData->playedAsParty ? 8 : 7,
                        'case' => 'winVsMegas'
                    ];
                }

                if($matchData->gamemode = 'All Random' && $matchData->win){
                    $possibleSettings[0]['titleId'] = 47;
                }

                // Win/Lose as Party/Solo with highest healing ingame
                if (in_array($matchData->healer, $matchData->playerIds)) {

                    $possibleSettings[] = [
                        'titleId' => 10,
                        'descriptionId' => $matchData->win ? ($matchData->playedAsParty ? 14 : 11) : ($matchData->playedAsParty ? 13 : 12),
                        'case' => 'healer'
                    ];
                }

                // Win/Lose as Solo with Farm over 500
                if ($matchData->playedAsParty === false && reset($matchData->players)->lastHits > 499){

                    $possibleSettings[] = [
                        'titleId' => 9,
                        'descriptionId' =>  $matchData->win ? 15 : 16,
                        'case' => 'farmer'
                    ];
                }

                // Win/Lose as Solo/Party as Topfragger
                if (in_array($matchData->topFragger, $matchData->playerIds)) {


                    $possibleSettings[] = [
                        'titleId' => null,
                        'descriptionId' => null,
                        'case' => 'topFragger'
                    ];
                }

                // Long Game over 55 min (win/lose & solo/party)
                if ($matchData->duration > 3300){

                    $possibleSettings[] = [
                        'titleId' => $matchData->playedAsParty ? null : 22,
                        'descriptionId' => null,
                        'case' => 'long'
                    ];
                }

                // Short Game under 30 min (win/lose & solo/party)
                if ($matchData->duration < 1800){

                    $possibleSettings[] = [
                        'titleId' => $matchData->win ? null : 22,
                        'descriptionId' => null,
                        'case' => 'short'
                    ];

                }

                // highest hero damage (solo/party) & (win/lose)
                if (in_array($matchData->carry, $matchData->playerIds)) {

                    $possibleSettings[] = [
                        'titleId' => null,
                        'descriptionId' => null,
                        'case' => 'carry'
                    ];

                }

                // highest hero damage (solo/party) & (win/lose)
                if ($matchData->isRanked) {

                    $possibleSettings[] = [
                        'titleId' => null,
                        'descriptionId' => null,
                        'case' => 'ranked'
                    ];

                }

                $allPlayerItems = [];
                array_map(function($player) use ($allPlayerItems) {

                    array_walk($player->items, function($item){

                         $allPlayerItems[] = $item;
                    });

                },$matchData->players);

                if(in_array(133,$allPlayerItems)){

                    $possibleSettings[] = [
                        'titleId' => null,
                        'descriptionId' => null,
                        'case' => 'rapier'
                    ];
                }
            /**
             * Weitere Cases:
             * Techies Pick
             * Captians Mode/draft
             * 5 Stack
             * Abandon
             * Türsteher Kombo
             *
             * stomps?
             */


            $selectedSettings = $possibleSettings[array_rand($possibleSettings)];


            $embedObjects[$embedObjectsCount]['title'] = $this->getTemplateSentence($selectedSettings['titleId'],'title',$matchData->playedAsParty,$matchData->win,$selectedSettings['case']);

            $embedObjects[$embedObjectsCount]['url'] = $matchData->url;

            $embedObjects[$embedObjectsCount]['description'] = $this->getTemplateSentence($selectedSettings['descriptionId'],'description',$matchData->playedAsParty,$matchData->win,$selectedSettings['case']);


            $embedObjects[$embedObjectsCount]['description'] = $this->replacePlaceholders($embedObjects[$embedObjectsCount]['description'],$matchData);
            $embedObjects[$embedObjectsCount]['title'] = $this->replacePlaceholders($embedObjects[$embedObjectsCount]['title'],$matchData);

            if(!$matchData->playedAsParty){

                $soloPlayer = reset($matchData->players);


                $thumbnailUrl = $this->getEmbedThumbnail($soloPlayer->hero);
                $thumbnailCurl = curl_init($thumbnailUrl);
                if(curl_getinfo($thumbnailCurl, CURLINFO_HTTP_CODE) < 400){
                    $embedObjects[$embedObjectsCount]['thumbnail']['url'] = $thumbnailUrl;
                    $embedObjects[$embedObjectsCount]['thumbnail']['height'] = 144;
                    $embedObjects[$embedObjectsCount]['thumbnail']['width'] = 256;
                }
                curl_close($thumbnailCurl);

            }

            $embedObjectsCount++;

        }

        return $embedObjects;
    }

    private function replacePlaceholders($templateString, $matchData){

        $replacedString = preg_replace_callback('(\$([A-Z]|[a-z]|[^\s\.!?,\)])+)', function($results) use ($matchData){

            $key = str_replace('$','',$results[0]);

            return $matchData->findTextValue($key);


        }, $templateString);

        return $replacedString;

    }

    private function getEmbedThumbnail($herostring){

        $heroslug = strtolower(str_replace(' ', '_',$herostring));

        return 'https://api.opendota.com/apps/dota2/images/heroes/' . $heroslug . '_full.png';
    }


    
}