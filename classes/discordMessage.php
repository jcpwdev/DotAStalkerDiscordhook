<?php
/**
 * Created by PhpStorm.
 * User: jcpw
 * Date: 30.03.18
 * Time: 11:23
 */

/**
 * This classes sends data to the specified discord webhook
 * it can send simple plain text messages or embed objects
 * username and avatar have default values but can/should be overwritten after initializing the class
 *
 **/

class discordMessage
{

    public $username;
    public $userimage;
    private $hookId;
    private $hookToken;
    private $finalJson;
    private $message;


    /**
     * discordMessage constructor.
     * @param $hookId (int/string)
     * @param $hookToken (string)
     *
     * Gathers required auth-data
     * loads default username and avatar
     */

    function __construct($hookId, $hookToken)
    {
        //required to estabilsh a connection to discord
        $this->hookId = $hookId;
        $this->hookToken = $hookToken;

        // define defaults. those have to be set manually
        $this->username = 'Discord Bot';
        $this->userimage = 'https://d1u5p3l4wpay3k.cloudfront.net/dota2_gamepedia/c/c0/Pudge_icon.png';

    }

    /**
     * @param $messageObject (string or array)
     * @return mixed
     *
     * sends a plain-text message or embed message to the discord hook
     */

    function send($messageObject){

        if(!$messageObject) return;

        // array? -> embed object message
        if(is_array($messageObject)) {

            $this->finalJson = json_encode([
                    "embeds" => $messageObject,
                    "username" => $this->username,
                    "avatar_url" => $this->userimage,
                ]
            );


        // just string? -> simple message
        } else {
            $this->finalJson = json_encode([
                    "content" => $messageObject,
                    "username" => $this->username,
                    "avatar_url" => $this->userimage,
                ]
            );
        }

        $ch = curl_init('https://discordapp.com/api/webhooks/' . $this->hookId . '/' . $this->hookToken );

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->finalJson);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($this->finalJson))
        );

        $result = curl_exec($ch);

        curl_close($ch);

        $this->message = $result . ' Success ';
    }

    public function showMessage() {
        return $this->message;
    }

}