<?php

namespace Storyblok;

use GuzzleHttp\Client as Guzzle;
use Apix\Cache as ApixCache;
use GuzzleHttp\RequestOptions;

/**
 * Storyblok Client
 */
class Client extends BaseClient
{
    const CACHE_VERSION_KEY = "storyblok:cache_version";
    const EXCEPTION_GENERIC_HTTP_ERROR = "An HTTP Error has occurred!";

    /**
     * @var string
     */
    public $cacheVersion;

    /**
     * @var string
     */
    private $linksPath = 'links/';

    /**
     * @var boolean
     */
    private $editModeEnabled;

    /**
     * @var string
     */
    private $release;

    /**
     * @var string
     */
    private $resolveRelations;

    /**
     * @var string
     */
    private $resolveLinks;

    /**
     * @var boolean
     */
    private $cacheNotFound;

    /**
     * @var Cache
     */
    protected $cache;
    
    /**
     * @var string
     */
    protected $language = 'default';
    
    /**
     * @var string
     */
    protected $fallbackLanguage = 'default';

    /**
     * @param string $apiKey
     * @param string $apiEndpoint
     * @param string $apiVersion
     * @param bool   $ssl
     */
    function __construct($apiKey = null, $apiEndpoint = "api.storyblok.com", $apiVersion = "v1", $ssl = false)
    {
        parent::__construct($apiKey, $apiEndpoint, $apiVersion, $ssl);

        if (isset($_GET['_storyblok'])) {
            $this->editModeEnabled = $_GET['_storyblok'];
            $this->release = $_GET['_storyblok_release'] ?? null;
        } else {
            $this->editModeEnabled = false;
        }
    }

    /**
     * Enables editmode to receive draft versions
     *
     * @param  boolean $enabled
     * @return Client
     */
    public function editMode($enabled = true)
    {
        $this->editModeEnabled = $enabled;
        return $this;
    }
    
    /**
     * Set the language the story should be retrieved in
     *
     * @param  string $language
     * @return Client
     */
    public function language($language = 'default')
    {
        $this->language = $language;
        return $this;
    }
    
    /**
     * Set the fallback language the story should be retrieved in
     *
     * @param  string $fallbackLanguage
     * @return Client
     */
    public function fallbackLanguage($fallbackLanguage = 'default')
    {
        $this->fallbackLanguage = $fallbackLanguage;
        return $this;
    }

    /**
     * Enables caching for 404 responses
     *
     * @param  boolean $enabled
     * @return Client
     */
    public function cacheNotFound($enabled = true)
    {
        $this->cacheNotFound = $enabled;
        return $this;
    }

    /**
     * Set cache driver and optional the cache path
     *
     * @param string $driver Driver
     * @param array $options Path for file cache
     * @return \Storyblok\Client
     */
    public function setCache($driver, $options = array())
    {
        $options['serializer'] = 'php';
        $options['prefix_key'] = 'storyblok:';
        $options['prefix_tag'] = 'storyblok:';

        switch ($driver) {
            case 'mysql':
                $dbh = $options['pdo'];
                $this->cache = new ApixCache\Pdo\Mysql($dbh, $options);

                break;

            case 'sqlite':
                $dbh = $options['pdo'];
                $this->cache = new ApixCache\Pdo\Sqlite($dbh, $options);

                break;

            case 'postgres':
                $dbh = $options['pdo'];
                $this->cache = new ApixCache\Pdo\Pgsql($dbh, $options);

                break;

            default:
                $options['directory'] = $options['path'];

                $this->cache = new ApixCache\Files($options);

                break;
        }

        $this->cacheVersion = $this->cache->load(self::CACHE_VERSION_KEY);

        if (!$this->cacheVersion) {
            $this->setCacheVersion();
        }

        return $this;
    }

    /**
     * Manually delete the cache of one item
     *
     * @param  string $slug Slug
     * @return \Storyblok\Client
     */
    public function deleteCacheBySlug($slug)
    {
        $key = $this->_getCacheKey('stories/' . $slug);

        if ($this->cache) {
            $this->cache->delete($key);

            // Always refresh cache of links
            $this->cache->delete($this->linksPath);
            $this->setCacheVersion();
        }

        return $this;
    }

    /**
     * Flush all cache
     *
     * @return \Storyblok\Client
     */
    public function flushCache()
    {
        if ($this->cache) {
            $this->cache->flush();
            $this->setCacheVersion();
        }

        return $this;
    }

    /**
     * Automatically delete the cache of one item if client sends published parameter
     *
     * @param  string $key Cache key
     * @return \Storyblok\Client
     */
    private function reCacheOnPublish($key)
    {
        if (isset($_GET['_storyblok_published']) && $this->cache) {
            $this->cache->delete($key);

            // Always refresh cache of links
            $this->cache->delete($this->linksPath);
            $this->setCacheVersion();
        }

        return $this;
    }

    /**
     * Sets cache version to get a fresh version from cdn after clearing the cache
     *
     * @return \Storyblok\Client
     */
    public function setCacheVersion()
    {
        if ($this->cache) {
            $timestamp = time();
            $this->cache->save($timestamp, self::CACHE_VERSION_KEY);
            $this->cacheVersion = $timestamp;
        }

        return $this;
    }

    /**
     * Gets cache version from cache or as timestamp
     *
     * @return Integer
     */
    function getCacheVersion()
    {
        if (empty($this->cacheVersion)) {
            return time();
        }

        return $this->cacheVersion;
    }

    /**
     * Pull story from a release for preview
     *
     * @param  string $release
     * @return Client
     */
    public function setRelease($release)
    {
        $this->release = $release;
        return $this;
    }

    /**
     * Gets a story by the slug identifier
     *
     * @param  string $slug Slug
     *
     * @return Client
     * @throws ApiException
     */
    public function getStoryBySlug($slug)
    {
        return $this->getStory($slug);
    }

    /**
     * Gets a story by it’s UUID
     *
     * @param string $uuid UUID
     *
     * @return Client
     * @throws ApiException
     */
    public function getStoryByUuid($uuid)
    {
        return $this->getStory($uuid, true);
    }

    /**
     * Gets a story
     *
     * @param  string $slug Slug
     * @param bool $byUuid
     *
     * @return Client
     * @throws ApiException
     */
    private function getStory($slug, $byUuid = false)
    {
        $version = 'published';

        if ($this->editModeEnabled) {
            $version = 'draft';
        }

        $key = 'stories/' . $slug;
        $cachekey = $this->_getCacheKey($key);

        $this->reCacheOnPublish($key);

        if ($version === 'published' && $this->cache && $cachedItem = $this->cache->load($cachekey)) {
            if ($this->cacheNotFound && $cachedItem->httpResponseCode == 404) {
                throw new ApiException(self::EXCEPTION_GENERIC_HTTP_ERROR, 404);
            }

            $this->_assignState($cachedItem);
        } else {
            $options = array(
                'token' => $this->getApiKey(),
                'version' => $version,
                'cache_version' => $this->getCacheVersion()
            );

            if ($byUuid) {
                $options['find_by'] = 'uuid';
            }

            if ($this->resolveRelations) {
                $options['resolve_relations'] = $this->resolveRelations;
            }

            if ($this->resolveLinks) {
                $options['resolve_links'] = $this->resolveLinks;
            }

            if ($this->release) {
                $options['from_release'] = $this->release;
            }
            
            if($byUuid) {
                if ($this->language) {
                    $options['language'] = $this->language;
                }

                if ($this->fallbackLanguage) {
                    $options['fallback_lang'] = $this->fallbackLanguage;
                }
            }

            try {
                $response = $this->get($key, $options);
                $this->_save($response, $cachekey, $version);
            } catch (\Exception $e) {
                if ($this->cacheNotFound && $e->getCode() === 404) {
                    $result = new \stdClass();
                    $result->httpResponseBody = [];
                    $result->httpResponseCode = 404;
                    $result->httpResponseHeaders = [];

                    $this->cache->save($result, $cachekey);
                }

                throw new ApiException(self::EXCEPTION_GENERIC_HTTP_ERROR . ' - ' . $e->getMessage(), $e->getCode());
            }
        }

        return $this;
    }

    /**
     * Gets a list of stories
     *
     * array(
     *    'starts_with' => $slug,
     *    'with_tag' => $tag,
     *    'sort_by' => $sort_by,
     *    'per_page' => 25,
     *    'page' => 0
     * )
     *
     *
     * @param  array $options Options
     * @return \Storyblok\Client
     */
    public function getStories($options = array())
    {
        $version = 'published';
        $endpointUrl = 'stories/';

        if ($this->editModeEnabled) {
            $version = 'draft';
        }

        $key = 'stories/' . serialize($this->_prepareOptionsForKey($options));
        $cachekey = $this->_getCacheKey($key);

        $this->reCacheOnPublish($key);

        if ($version === 'published' && $this->cache && $cachedItem = $this->cache->load($cachekey)) {
            $this->_assignState($cachedItem);
        } else {
            $options = array_merge($options, array(
                'token' => $this->getApiKey(),
                'version' => $version,
                'cache_version' => $this->getCacheVersion()
            ));

            if ($this->resolveRelations) {
                $options['resolve_relations'] = $this->resolveRelations;
            }
            
            if ($this->language) {
                $options['language'] = $this->language;
            }
            
            if ($this->fallbackLanguage) {
                $options['fallback_lang'] = $this->fallbackLanguage;
            }

            $response = $this->get($endpointUrl, $options);

            $this->_save($response, $cachekey, $version);
        }

        return $this;
    }

    /**
     *  Sets global reference.
     *
     *  eg. global.global_referece
     *
     * @param $reference
     * @return $this
     */
    public function resolveRelations($reference)
    {
        $this->resolveRelations = $reference;

        return $this;
    }

    /**
     * Set reference for how to resolve links.
     *
     * @param $reference
     * @return $this
     */
    public function resolveLinks($reference)
    {
        $this->resolveLinks = $reference;

        return $this;
    }

    /**
     * Gets a list of tags
     *
     * array(
     *    'starts_with' => $slug
     * )
     *
     *
     * @param  array $options Options
     * @return \Storyblok\Client
     */
    public function getTags($options = array())
    {
        $version = 'published';
        $endpointUrl = 'tags/';

        if ($this->editModeEnabled) {
            $version = 'draft';
        }

        $key = 'tags/' . serialize($options);
        $cachekey = $this->_getCacheKey($key);

        $this->reCacheOnPublish($key);

        if ($version === 'published' && $this->cache && $cachedItem = $this->cache->load($cachekey)) {
            $this->_assignState($cachedItem);
        } else {
            $options = array_merge($options, array(
                'token' => $this->getApiKey(),
                'version' => $version,
                'cache_version' => $this->getCacheVersion()
            ));

            $response = $this->get($endpointUrl, $options);

            $this->_save($response, $cachekey, $version);
        }

        return $this;
    }

    /**
     * Gets a list of datasource entries
     *
     * @param  string $slug Slug
     * @param  array $options Options
     * @return \Storyblok\Client
     */
    public function getDatasourceEntries($slug, $options = array())
    {
        $version = 'published';
        $endpointUrl = 'datasource_entries/';

        if ($this->editModeEnabled) {
            $version = 'draft';
        }

        $key = 'datasource_entries/' . $slug . '/' . serialize($options);
        $cachekey = $this->_getCacheKey($key);

        $this->reCacheOnPublish($key);

        if ($version === 'published' && $this->cache && $cachedItem = $this->cache->load($cachekey)) {
            $this->_assignState($cachedItem);
        } else {
            $options = array_merge($options, array(
                'token' => $this->getApiKey(),
                'version' => $version,
                'cache_version' => $this->getCacheVersion(),
                'datasource' => $slug
            ));

            $response = $this->get($endpointUrl, $options);

            $this->_save($response, $cachekey, $version);
        }

        return $this;
    }

    /**
     * Gets a list of tags
     *
     * array(
     *    'starts_with' => $slug
     * )
     *
     *
     * @param  array $options Options
     * @return \Storyblok\Client
     */
    public function getLinks($options = array())
    {
        $version = 'published';

        $key = $this->linksPath;
        $cachekey = $this->_getCacheKey($key);

        if ($this->editModeEnabled) {
            $version = 'draft';
        }

        if ($version === 'published' && $this->cache && $cachedItem = $this->cache->load($cachekey)) {
            $this->_assignState($cachedItem);
        } else {
            $options = array_merge($options, array(
                'token' => $this->getApiKey(),
                'version' => $version,
                'cache_version' => $this->getCacheVersion()
            ));

            $response = $this->get($key, $options);

            $this->_save($response, $cachekey, $version);
        }

        return $this;
    }

    /**
     * Transforms datasources into a ['name']['value'] array.
     *
     * @return array
     */
    public function getAsNameValueArray()
    {
        if (!isset($this->responseBody)) {
            return array();
        }

        $array = [];

        foreach ($this->responseBody['datasource_entries'] as $entry) {
            if (!isset($array[$entry['name']])) {
                $array[$entry['name']] = $entry['value'];
            }
        }

        return $array;
    }

    /**
     * Transforms tags into a string array.
     *
     * @return array
     */
    public function getTagsAsStringArray()
    {
        if (!isset($this->responseBody)) {
            return array();
        }

        $array = [];

        foreach ($this->responseBody['tags'] as $entry) {
            $array[] = $entry['name'];
        }

        return $array;
    }

    /**
     * Transforms links into a tree
     *
     * @return array
     */
    public function getAsTree()
    {
        if (!isset($this->responseBody)) {
            return array();
        }

        $tree = [];

        foreach ($this->responseBody['links'] as $item) {
            if (!isset($tree[$item['parent_id']])) {
                $tree[$item['parent_id']] = array();
            }

            $tree[$item['parent_id']][] = $item;
        }

        return $this->_generateTree($tree, 0);
    }

    /**
     * Recursive function to generate tree
     *
     * @param  array  $items
     * @param  integer $parent
     * @return array
     */
    private function _generateTree($items, $parent = 0)
    {
        $tree = array();

        if (isset($items[$parent])) {
            $result = $items[$parent];

            foreach ($result as $item) {
                if (!isset($tree[$item['id']])) {
                    $tree[$item['id']] = array();
                }

                $tree[$item['id']]['item']  = $item;
                $tree[$item['id']]['children']  = $this->_generateTree($items, $item['id']);
            }
        }

        return $tree;
    }

    /**
     * Save's the current response in the cache if version is published
     *
     * @param  array $response
     * @param  string $key
     * @param  string $version
     */
    private function _save($response, $key, $version)
    {
        $this->_assignState($response);

        if ($this->cache &&
            $version === 'published' &&
            $response->httpResponseHeaders &&
            $response->httpResponseCode == 200) {

            $this->cache->save($response, $key);
        }
    }

    /**
     * Assigns the httpResponseBody and httpResponseHeader to '$this';
     *
     * @param  array $response
     * @param  string $key
     * @param  string $version
     */
    private function _assignState($response) {
        $this->responseBody = $response->httpResponseBody;
        $this->responseHeaders = $response->httpResponseHeaders;
    }

    /**
     * prepares to Options for the cache key. Fixes some issues for too long filenames if filecache is used.
     *
     * @param  array $response
     * @param  string $key
     * @param  string $version
     */
    private function _prepareOptionsForKey($options) {
        $prepared = array();
        $keyOrder = array();
        foreach($options as $key => $value) {
            $prepared[] = $value;
            $keyOrder[] = substr($key, 0, 1);
        }
        $prepared[] = implode('', $keyOrder);
        return $prepared;
    }

    private function _getCacheKey($key = '')
    {
        return hash('sha256', $key);
    }
}
