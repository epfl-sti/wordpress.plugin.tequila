<?php

if (! defined('ABSPATH')) {
    die('Access denied.');
}

define('LNG_DEUTSCH', 2);
define('LNG_ENGLISH', 1);
define('LNG_FRENCH', 0);


class TequilaClient
{
    public $isTest = false;
    public $iLanguage = LNG_FRENCH;
    public $aLanguages = array(
         LNG_ENGLISH => 'english',
          LNG_FRENCH => 'francais',
         );

    /* GOAL : Launch the user authentication
     * Precondition: The user is *not* logged in
     */
    public function Authenticate($urlaccess = '')
    {
        $request_key = $this->createRequest($urlaccess);
        $url = $this->getAuthenticationUrl($request_key);
        header('Location: ' . $url);
        exit;
    }

    /*
        GOAL : Sends an authentication request to Tequila
    */
    public function createRequest($urlaccess = '')
    {

    /* If application URL not initialized,
       we try to generate it automatically */
        if (empty($urlaccess)) {
            $urlaccess = ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on'))
                ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . ":" . $_SERVER['SERVER_PORT'] . $_SERVER['PHP_SELF'];
            if (isset($_SERVER['PATH_INFO'])) {
                $urlaccess .= $_SERVER['PATH_INFO'];
            }
            if (isset($_SERVER['QUERY_STRING'])) {
                $urlaccess .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        /* Request creation */
        $requestInfos = array();
        $requestInfos ['urlaccess'] = $urlaccess;

        if (!empty($this->sApplicationName)) {
            $requestInfos ['service'] = $this->sApplicationName;
        }
        if (!empty($this->aWantedRights)) {
            $requestInfos ['wantright'] = implode($this->aWantedRights, '+');
        }
        if (!empty($this->aWantedRoles)) {
            $requestInfos ['wantrole'] =  implode($this->aWantedRoles, '+');
        }
        if (!empty($this->aWantedAttributes)) {
            $requestInfos ['request'] = implode($this->aWantedAttributes, '+');
        }
        if (!empty($this->aWishedAttributes)) {
            $requestInfos ['wish'] = implode($this->aWishedAttributes, '+');
        }
        if (!empty($this->aWantedGroups)) {
            $requestInfos ['belongs'] = implode($this->aWantedGroups, '+');
        }
        if (!empty($this->sCustomFilter)) {
            $requestInfos ['require'] = $this->sCustomFilter;
        }
        if (!empty($this->sAllowsFilter)) {
            $requestInfos ['allows'] = $this->sAllowsFilter;
        }
        if (!empty($this->iLanguage)) {
            $requestInfos ['language'] = $this->aLanguages [$this->iLanguage];
        }

        /* Asking tequila */
        $response = $this->askTequila('createrequest', $requestInfos);

        return substr(trim($response), 4); // 4 = strlen ('key=')
    }
    public function getAuthenticationUrl($request_key)
    {
        return sprintf(
            '%s/requestauth?requestkey=%s',
            $this->serverUrl(),
            $request_key
          );
    }

    /* GOAL : Checks that user has correctly authenticated and retrieves its data.
       Precondition: Call this when the query string contains ?key= on the redirect
       path back from Tequila. $sessionkey should be the value of ?key=

        @return mixed
    */
    public function fetchAttributes($fields)
    {
        if (! is_array($fields)) {
            $fields = array('key' => $fields);
        }
        $response = $this->askTequila('fetchattributes', $fields);
        if (!$response) {
            die("Unknown Tequila key: $sessionkey");
        }

        $result = array();
        $attributes = explode("\n", $response);

        /* Saving returned attributes */
        foreach ($attributes as $attribute) {
            $attribute = trim($attribute);
            if (!$attribute) {
                continue;
            }
            list($key, $val) = explode('=', $attribute, 2);
            //if ($key ==  'key') { $this->key  = $val; }
            //if ($key ==  'org') { $this->org  = $val; }
            //if ($key == 'user') { $this->user = $val; }
            //if ($key == 'host') { $this->host = $val; }
            $result [$key] = $val;
        }
        return $result;
    }

    public function askTequila($type, $fields = array())
    {
        //Use the CURL object in order to communicate with tequila.epfl.ch
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);

        $url = $this->serverUrl();
        $is_post = false;   // Unless stipulated otherwise below
        switch ($type) {
        case 'createrequest':
            $url .= '/createrequest';
            curl_setopt($ch, CURLOPT_POST, true);
            $is_post = true;
            break;

        case 'fetchattributes':
            $url .= '/fetchattributes';
            break;

        case 'config':
            $url .= '/getconfig';
            break;

        case 'logout':
            $url .= '/logout';
            break;

        default:
            return;
        }
        // $url contains the tequila server with the parameters to execute
        curl_setopt($ch, CURLOPT_URL, $url);

        /* If fields where passed as parameters, */
        if (is_array($fields) && count($fields)) {
            $pFields = array();
            foreach ($fields as $key => $val) {
                $pFields[] = sprintf('%s=%s', $key, $val);
            }
            if ($is_post) {
                $query = implode("\n", $pFields) . "\n";
                curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
            } else {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . implode('&', $pFields));
            }
        }
        $response = curl_exec($ch);
        file_put_contents('/tmp/tequila_response', $response);
        // If connexion failed (HTTP code 200 <=> OK)
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code != '200') {
            die("Error communicating with Tequila ($url), " . 
                "status is $code\n\n$query\n\nResponse follows:\n$response\n");
        }
        curl_close($ch);
        $response_body = preg_replace('/^.*?\n\r?\n/s', '', $response, 1);
        file_put_contents('/tmp/tequila_response', $response_body);
        return $response_body;
    }

    /* Caller of Authenticate() may call these accessors to improvify
     * the user experience and the loot of data that Tequila returns. */
    public function SetApplicationName($sApplicationName)
    {
        $this->sApplicationName = $sApplicationName;
    }
    public function SetLanguage($sLanguage)
    {
        $this->iLanguage = $sLanguage;
    }
    public function SetWantedAttributes($aWantedAttributes)
    {
        $this->aWantedAttributes = $aWantedAttributes;
    }
    public function AddWantedAttributes($aWantedAttributes)
    {
        $this->aWantedAttributes = array_merge(
            $this->aWantedAttributes,
                  $aWantedAttributes
        );
    }
    public function RemoveWantedAttributes($aWantedAttributes)
    {
        foreach ($this->aWantedAttributes as $sWantedAttribute) {
            if (in_array($sWantedAttribute, $aWantedAttributes)) {
                unset($this->aWantedAttributes [array_search(
                    $sWantedAttribute,
                    $this->aWantedAttributes
                    )]
                );
            }
        }
    }

    public function SetWishedAttributes($aWishedAttributes)
    {
        $this->aWishedAttributes = $aWishedAttributes;
    }
    public function AddWishedAttributes($aWishedAttributes)
    {
        $this->aWishedAttributes = array_merge(
            $this->aWishedAttributes,
                  $aWishedAttributes
        );
    }
    public function RemoveWishedAttributes($aWishedAttributes)
    {
        foreach ($this->aWishedAttributes as $aWishedAttribute) {
            if (in_array($aWishedAttribute, $aWishedAttributes)) {
                unset($this->aWishedAttributes[array_search(
                        $aWishedAttribute,
                        $this->aWishedAttributes
                    )]
                );
            }
        }
    }

    public function serverUrl() 
    {
        if ($this->isTest) {
            $server = "test-tequila";
        } else {
            $server = "tequila";
        }
        return "https://$server.epfl.ch/cgi-bin/tequila";
    }
}
