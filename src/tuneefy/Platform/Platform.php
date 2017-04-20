<?php

namespace tuneefy\Platform;

use MarkWilson\XmlToJson\XmlToJsonConverter;
use tuneefy\Utils\OAuth\OAuthConsumer;
use tuneefy\Utils\OAuth\OAuthRequest;
use tuneefy\Utils\OAuth\OAuthSignatureMethod_HMAC_SHA1;
use tuneefy\Utils\Utils;

abstract class Platform implements GeneralPlatformInterface
{
    const NAME = '';
    const TAG = '';
    const COLOR = 'FFFFFF';

    // Helper Regexes
    const REGEX_FULLSTRING = "[a-zA-Z0-9%\+\-\s\_\.]*";
    const REGEX_NUMERIC_ID = '[0-9]*';

    // Helper constants for API calls
    const LOOKUP_TRACK = 0;
    const LOOKUP_ALBUM = 1;
    const LOOKUP_ARTIST = 2;

    const SEARCH_TRACK = 3;
    const SEARCH_ALBUM = 4;
    const SEARCH_ARTIST = 5;

    // The 'mode' indicates whether
    // we're going to eagerly fetch data
    // when it's missing from the platform
    // response
    const MODE_LAZY = 0;
    const MODE_EAGER = 1;

    // Different HTTP Methods used
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';

    // Different Return content-type
    const RETURN_JSON = 1;
    const RETURN_XML = 2;

    // Default limit for requests
    const LIMIT = 10; // 1 < LIMIT < 25
    const AGGREGATE_LIMIT = 50; // The more the merrier

    protected $default;

    protected $enables;
    protected $capabilities;

    protected $key = '';
    protected $secret = '';

    // Redeclared in child classes
    const API_ENDPOINT = '';
    const API_METHOD = self::METHOD_GET;
    const RETURN_CONTENT_TYPE = self::RETURN_JSON;
    const NEEDS_OAUTH = false;

    protected $endpoints = [];
    protected $terms = [];
    protected $options = [];

    /**
     * The singleton instance of the class.
     */
    protected static $instances = [];

    /**
     * Protected constructor to ensure there are no instantiations.
     */
    final protected function __construct()
    {
        $this->default = false;
        $this->capabilities = [];
        $this->enables = [];
        $this->key = '';
        $this->secret = '';
    }

    /**
     * Retrieves the singleton instance.
     * We need a Map of instances otherwise
     * only one instance of child class will
     * we created.
     */
    public static function getInstance(): Platform
    {
        $class = get_called_class();
        if (!isset(static::$instances[$class]) || static::$instances[$class] === null) {
            static::$instances[$class] = new static();
        }

        return static::$instances[$class];
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getTag(): string
    {
        return static::TAG;
    }

    public function getColor(): string
    {
        return static::COLOR;
    }

    // Credentials
    public function setCredentials(string $key, string $secret): Platform
    {
        $this->key = $key;
        $this->secret = $secret;
        return $this;
    }

    // Enabled & default
    public function setEnables(array $enables): Platform
    {
        $this->enables = $enables;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isEnabledForApi(): bool
    {
        return $this->enables['api'];
    }

    public function isEnabledForWebsite(): bool
    {
        return $this->enables['website'];
    }

    // Capabilities
    public function setCapabilities(array $capabilities): Platform
    {
        $this->capabilities = $capabilities;
        return $this;
    }

    public function isCapableOfSearchingTracks(): bool
    {
        return $this->capabilities['track_search'];
    }

    public function isCapableOfSearchingAlbums(): bool
    {
        return $this->capabilities['album_search'];
    }

    public function isCapableOfLookingUp(): bool
    {
        return $this->capabilities['lookup'];
    }

    // This function, or its children class' counterpart,
    // is called in the fetch method to give the child
    // class a chance to add other contextual options
    protected function addContextOptions(array $data): array
    {
        return $data;
    }

    protected function fetchSync(int $type, string $query)
    {
        $url = $this->endpoints[$type];

        if ($this->terms[$type] === null) {
            $url = sprintf($url, $query);
            $data = $this->options[$type];
        } else {
            $data = array_merge([$this->terms[$type] => $query], $this->options[$type]);
        }

        // Gives the child class a chance to add options that are
        // contextual to the request, eg. for Xbox, a token, or
        // just simply the API key ...
        $data = $this->addContextOptions($data);

        if (static::NEEDS_OAUTH) {
            // We add the signature to the request data
            $consumer = new OAuthConsumer($this->key, $this->secret, null);
            $req = OAuthRequest::from_consumer_and_token($consumer, null, static::API_METHOD, $url, $data->toArray());
            $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
            $req->sign_request($hmac_method, $consumer, null);

            array_merge($data, $req->get_parameters());
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => 1, // Some APIs redirect to content with a 3XX code
        ]);

        if (static::API_METHOD === self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_URL, $url.'?'.http_build_query($data)); // It's ok to have a trailing "?"
        } elseif (static::API_METHOD === self::METHOD_POST) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            // Error in the request, we should gracefully fail returning null
            // FIXME
            return null;
        } else {
            if (static::RETURN_CONTENT_TYPE === self::RETURN_XML) {
                $response = Utils::flattenMetaXMLNodes($response);
                $converter = new XmlToJsonConverter();
                try {
                    $xml = new \SimpleXMLElement($response);
                    $response = $converter->convert($xml);
                } catch (\Exception $e) {
                    return null; // XML can't be parsed
                }
            }

            // For some platforms
            $response = Utils::removeBOM($response);
            
            // If there is a problem with the data, we want to return null to gracefully fail as well :
            // "NULL is returned if the json cannot be decoded or if the encoded data is deeper than the recursion limit."
            //
            // Why the "data" key ? It's to cope with the result of json_decode. If the first level of $response
            // is pure array, json_decode will return a array object instead of an expected stdClass object.
            // To bypass that, we force the inclusion of the response in a data key, making it de facto an object.
            return json_decode('{"data":'.$response.'}', false);
        }
    }

    abstract public function search(int $type, string $query, int $limit, int $mode);
}