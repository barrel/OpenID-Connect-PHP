<?php

/**
 *
 * Copyright MITRE 2012
 *
 * OpenIDConnectClient for PHP5
 * Author: Michael Jett <mjett@mitre.org>
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 *
 */

/**
 * Use session to manage a nonce
 */
if (!isset($_SESSION)) {
    session_start();
}

/**
 * OpenIDConnect Exception Class
 */
class OpenIDConnectClientException extends Exception
{

}

/**
 * Require the CURL and JSON PHP extentions to be installed
 */
if (!function_exists('curl_init')) {
    throw new OpenIDConnectClientException('OpenIDConnect needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
    throw new OpenIDConnectClientException('OpenIDConnect needs the JSON PHP extension.');
}

/**
 *
 * Please note this class stores nonces in $_SESSION['openid_connect_nonce']
 *
 */
class OpenIDConnectClient
{

    /**
     * @var string arbitrary id value
     */
    private $clientID;

    /**
     * @var string arbitrary secret value
     */
    private $clientSecret;

    /**
     * @var array holds the provider configuration
     */
    private $providerConfig = array();

    /**
     * @var string http proxy if necessary
     */
    private $httpProxy;

    /**
     * @var string full system path to the SSL certificate
     */
    private $certPath;

    /**
     * @var string if we aquire an access token it will be stored here
     */
    private $accessToken;

    /**
     * @var array holds scopes
     */
    private $scopes = array();

    /**
     * @var array holds a cache of info returned from the user info endpoint
     */
    private $userInfo = array();

    /**
     * @param $client_id
     * @param $client_secret
     * @param $provider_url
     */
    public function __construct($client_id, $client_secret, $provider_url) {
        $this->clientID = $client_id;
        $this->clientSecret = $client_secret;
        $this->providerConfig['issuer'] = $provider_url;
    }

    /**
     * @return bool
     * @throws OpenIDConnectClientException
     */
    public function authenticate() {

        $code = @$_REQUEST["code"];

        // If we have an authorization code then proceed to request a token
        if ($code) {

            $token_json = self::requestTokens($code);

            // Throw an error if the server returns one
            if (isset($token_json->error)) {
                throw new OpenIDConnectClientException($token_json->error);
            }

            // Do an OpenID Connect session check
            if ($_REQUEST['state'] != $_SESSION['openid_connect_state']){
                throw new OpenIDConnectClientException("Unable to determine state");
            }

            $claims = self::decodeJWT($token_json->id_token, 1);

            // If this is a valid claim
            if (self::verifyJWTclaims($claims)) {

                // Clean up the session a little
                unset($_SESSION['openid_connect_nonce']);

                // Save the access token
                $this->accessToken = $token_json->access_token;

                // Success!
                return true;

            } else {
                throw new OpenIDConnectClientException ("Unable to verify JWT claims");
            }

        } else {

            self::requestAuthorization();
            return false;
        }

    }

    /**
     * @param $scope - example: openid, given_name, etc...
     */
    public function addScope($scope) {
        $this->scopes = array_merge($this->scopes, (array)$scope);
    }

    /**
     * Get's anything that we need configuration wise including endpoints, and other values
     *
     * @param $param
     * @return string
     */
    private function getConfigValue($param) {

        // If the configuration value is not available, attempt to fetch it from a well known config endpoint
        // This is also known as auto "discovery"
        if (!isset($this->providerConfig[$param])) {
            $well_known_config_url = self::getProviderURL() . "/.well-known/openid-configuration";
            $value = json_decode(self::fetchURL($well_known_config_url))->{$param};

            if ($value) {
                $this->providerConfig[$param] = $value;
            }

        }

        return $this->providerConfig[$param];
    }

    /**
     * Gets the URL of the current page we are on, encodes, and returns it
     *
     * @return string
     */
    private function getRedirectURL() {

        /**
         * Thank you
         * http://stackoverflow.com/questions/189113/how-do-i-get-current-page-full-url-in-php-on-a-windows-iis-server
         */
        $base_page_url = (@$_SERVER["HTTPS"] == "on") ? "https://" : "http://";
        if ($_SERVER["SERVER_PORT"] != "80") {
            $base_page_url .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"];
        } else {
            $base_page_url .= $_SERVER["SERVER_NAME"];
        }

        if (!isset($this->providerConfig['request_uri'])) {
	        $base_page_url .= reset(explode("?", $_SERVER['REQUEST_URI']));
        } else {
            $base_page_url = $this->providerConfig['request_uri'];
            $base_page_url = explode("?", $base_page_url);
            $base_page_url = $base_page_url[0];
        }

        // encode the URL so we can pass it back as a parameter
        return urlencode($base_page_url);
    }

    /**
     * Used for arbitrary value generation for nonces and state
     *
     * @return string
     */
    private function generateRandString () {
        return md5(uniqid(rand(), TRUE));
    }

    /**
     * Start Here
     * @return void
     */
    private function requestAuthorization() {

        $auth_endpoint = self::getConfigValue("authorization_endpoint");
        $response_type = "code";

        // Fetch scopes
        $scope = urlencode(implode(' ', $this->scopes));

        // Generate and store a nonce in the session
        // The nonce is an arbitrary value
        $nonce = self::generateRandString();
        $_SESSION['openid_connect_nonce'] = $nonce;

        // State essentially acts as a session key for OIDC
        $state = self::generateRandString();
        $_SESSION['openid_connect_state'] = $state;

        $auth_endpoint .= "?response_type=" . $response_type
            . "&client_id=" . $this->clientID
            . "&redirect_uri=" . self::getRedirectURL()
        //  . "&nonce=" . $nonce
            . "&state=" . $state;

        // If the client has been registered with additional scopes
        if (sizeof($this->scopes) > 0) {
            $auth_endpoint .= "&scope=" . $scope;
        }

        self::redirect($auth_endpoint);

    }


    /**
     * Requests ID and Access tokens
     *
     * @param $code
     * @return mixed
     */
    private function requestTokens($code) {

        $token_endpoint = self::getConfigValue("token_endpoint");
        $grant_type = "authorization_code";

        $token_endpoint .= "?grant_type=" . $grant_type
            . "&code=" . $code
            . "&redirect_uri=" . self::getRedirectURL()
            . "&client_id=" . $this->clientID
            . "&client_secret=" . $this->clientSecret;

        return json_decode(self::fetchURL($token_endpoint, "POST"));

    }

    /**
     * @param object $claims
     * @return bool
     */
    private function verifyJWTclaims($claims) {
        return (($claims->iss == self::getProviderURL())
            && ($claims->aud == $this->clientID));
        //  && ($claims->nonce == $_SESSION['openid_connect_nonce']));

    }

    /**
     * @param $jwt string encoded JWT
     * @param int $section the section we would like to decode
     * @return object
     */
    private function decodeJWT($jwt, $section = 0) {

        $parts = explode(".", $jwt);
        return json_decode(base64_decode($parts[$section]));
    }

    /**
     *
     * @param $attribute
     *
     * Attribute	    Type	Description
     * user_id 	        string 	REQUIRED Identifier for the End-User at the Issuer.
     * name 	        string 	End-User's full name in displayable form including all name parts, ordered according to End-User's locale and preferences.
     * given_name 	    string 	Given name or first name of the End-User.
     * family_name 	    string 	Surname or last name of the End-User.
     * middle_name 	    string 	Middle name of the End-User.
     * nickname 	    string 	Casual name of the End-User that may or may not be the same as the given_name. For instance, a nickname value of Mike might be returned alongside a given_name value of Michael.
     * profile 	        string 	URL of End-User's profile page.
     * picture 	        string 	URL of the End-User's profile picture.
     * website 	        string 	URL of End-User's web page or blog.
     * email 	        string 	The End-User's preferred e-mail address.
     * verified 	    boolean 	True if the End-User's e-mail address has been verified; otherwise false.
     * gender 	        string 	The End-User's gender: Values defined by this specification are female and male. Other values MAY be used when neither of the defined values are applicable.
     * birthday 	    string 	The End-User's birthday, represented as a date string in MM/DD/YYYY format. The year MAY be 0000, indicating that it is omitted.
     * zoneinfo 	    string 	String from zoneinfo [zoneinfo] time zone database. For example, Europe/Paris or America/Los_Angeles.
     * locale 	        string 	The End-User's locale, represented as a BCP47 [RFC5646] language tag. This is typically an ISO 639-1 Alpha-2 [ISO639‑1] language code in lowercase and an ISO 3166-1 Alpha-2 [ISO3166‑1] country code in uppercase, separated by a dash. For example, en-US or fr-CA. As a compatibility note, some implementations have used an underscore as the separator rather than a dash, for example, en_US; Implementations MAY choose to accept this locale syntax as well.
     * phone_number 	string 	The End-User's preferred telephone number. E.164 [E.164] is RECOMMENDED as the format of this Claim. For example, +1 (425) 555-1212 or +56 (2) 687 2400.
     * address 	        JSON object 	The End-User's preferred address. The value of the address member is a JSON [RFC4627] structure containing some or all of the members defined in Section 2.4.2.1.
     * updated_time 	string 	Time the End-User's information was last updated, represented as a RFC 3339 [RFC3339] datetime. For example, 2011-01-03T23:58:42+0000.
     *
     * @return mixed
     *
     */
    public function requestUserInfo($attribute) {

        // Check to see if the attribute is already in memory
        if (array_key_exists($attribute, $this->userInfo)) {
            return $this->userInfo->$attribute;
        }

        $user_info_endpoint = self::getConfigValue("userinfo_endpoint");
        $schema = 'openid';

        $user_info_endpoint .= "?schema=" . $schema
            . "&access_token=" . $this->accessToken;

        $user_json = json_decode(self::fetchURL($user_info_endpoint));

        $this->userInfo = $user_json;

        if (array_key_exists($attribute, $this->userInfo)) {
            return $this->userInfo->$attribute;
        }

        return null;

    }


    /**
     * @param $url
     * @throws OpenIDConnectClientException
     * @return mixed
     */
    private function fetchURL($url, $method = "GET") {

        // OK cool - then let's create a new cURL resource handle
        $ch = curl_init();

        // Now set some options (most are optional)

        // Set URL to download
 		if ($method=="POST"){
			$url_parts = explode("?", $url);
			$query_parts = explode("&", $url_parts[1]);
			curl_setopt($ch,CURLOPT_URL, $url_parts[0]);
			curl_setopt($ch,CURLOPT_POST, count($query_parts));
			curl_setopt($ch,CURLOPT_POSTFIELDS, $url_parts[1]);
		} else {
	       curl_setopt($ch, CURLOPT_URL, $url);
		}


        if (isset($this->httpProxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->httpProxy);
        }

        // Include header in result? (0 = yes, 1 = no)
        curl_setopt($ch, CURLOPT_HEADER, 0);

        /**
         * Jon Maul <maul@mitre.org>
         *
         * A quick fix for SSL
         *      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
         *
         * Otherwise we need to set cURL to trust a specific root certificate
         */
        if (isset($this->certPath)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $this->certPath);
            curl_setopt($ch, CURLOPT_CAPATH, NULL);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        }

        // Should cURL return or print out the data? (true = return, false = print)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Timeout in seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Download the given URL, and return output
        $output = curl_exec($ch);

        if (curl_exec($ch) === false) {
            throw new OpenIDConnectClientException('Curl error: ' . curl_error($ch));
            //die('Curl error: ' . curl_error($ch));
        }

        // Close the cURL resource, and free system resources
        curl_close($ch);

        return $output;
    }

    /**
     * @return string
     * @throws OpenIDConnectClientException
     */
    public function getProviderURL() {

        if (!isset($this->providerConfig['issuer'])) {
            throw new OpenIDConnectClientException("The provider URL has not been set");
        } else {
            return rtrim($this->providerConfig['issuer'], '/');
        }
    }

    /**
     * @param $url
     */
    public function redirect($url) {
        header('Location: ' . $url);
        exit;
    }

    /**
     * @param $httpProxy
     */
    public function setHttpProxy($httpProxy) {
        $this->httpProxy = $httpProxy;
    }

    /**
     * @param $certPath
     */
    public function setCertPath($certPath) {
        $this->certPath = $certPath;
    }

    /**
     *
     * Use this to alter a provider's endpoints and other attributes
     *
     * @param $array
     *        simple key => value
     */
    public function addConfigParam($array) {
        $this->providerConfig = array_merge($this->providerConfig, $array);
    }

    /**
     * @param $clientSecret
     */
    public function setClientSecret($clientSecret) {
        $this->clientSecret = $clientSecret;
    }

    /**
     * @param $clientID
     */
    public function setClientID($clientID) {
        $this->clientID = $clientID;
    }


}
